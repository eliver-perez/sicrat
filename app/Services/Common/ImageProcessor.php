<?php

declare(strict_types=1);

namespace App\Services\Common;

class ImageProcessor
{
	public function __construct(private int $maximum = 1920,private int $quality = 80,private int $thumbnail = 320) {}
	public function process(string $source,string $normal,string $thumb): array
	{
		if (!extension_loaded('gd') || !function_exists('imagewebp')) throw new ServiceFailure('Se requiere GD con soporte WebP para procesar imágenes.',false);
		$info=@getimagesize($source);
		if (!$info || !in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true) || $info[0]<1 || $info[1]<1 || $info[0]*$info[1]>24000000) throw new ServiceFailure('Imagen inválida o dimensiones excesivas.',false);
		$image=match ($info[2]) { IMAGETYPE_JPEG=>@imagecreatefromjpeg($source),IMAGETYPE_PNG=>@imagecreatefrompng($source),IMAGETYPE_WEBP=>@imagecreatefromwebp($source) };
		if (!$image) throw new ServiceFailure('La imagen no puede decodificarse.',false);
		try {
			if ($info[2]===IMAGETYPE_JPEG) {
				if (!function_exists('exif_read_data')) throw new ServiceFailure('Se requiere EXIF para corregir orientación JPEG.',false);
				$exif=@exif_read_data($source,'IFD0'); $orientation=(int) ($exif['Orientation'] ?? 1);
				if (in_array($orientation,[2,4,5,7],true)) imageflip($image,in_array($orientation,[2,5,7],true) ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
				$rotation=match ($orientation) { 3=>180,5,6=>-90,7,8=>90,default=>0 };
				if ($rotation!==0) { $rotated=imagerotate($image,$rotation,0); if (!$rotated) throw new ServiceFailure('No se pudo orientar la imagen.',false); $image=$rotated; }
			}
			// La recodificación de píxeles no copia EXIF, GPS ni contenido adjunto al original.
			$normalInfo=$this->variant($image,$normal,max(320,min(4096,$this->maximum)));
			$thumbInfo=$this->variant($image,$thumb,max(64,min(640,$this->thumbnail)));
			return ['normal'=>$normalInfo,'thumb'=>$thumbInfo];
		} finally { unset($image); }
	}
	private function variant(\GdImage $source,string $path,int $maximum): array
	{
		$width=imagesx($source); $height=imagesy($source); $ratio=min(1,$maximum/max($width,$height));
		$width=max(1,(int) round($width*$ratio)); $height=max(1,(int) round($height*$ratio));
		$target=imagecreatetruecolor($width,$height); imagealphablending($target,false); imagesavealpha($target,true);
		imagefilledrectangle($target,0,0,$width,$height,imagecolorallocatealpha($target,0,0,0,127));
		try {
			imagecopyresampled($target,$source,0,0,0,0,$width,$height,imagesx($source),imagesy($source));
			if (!imagewebp($target,$path,max(1,min(100,$this->quality)))) throw new ServiceFailure('No se pudo escribir la imagen.');
			chmod($path,0600);
			return ['width'=>$width,'height'=>$height];
		} finally { unset($target); }
	}
}
