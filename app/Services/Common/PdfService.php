<?php

declare(strict_types=1);

namespace App\Services\Common;

class PdfService
{
	public function table(string $path,string $title,array $columns,iterable $rows,array $context,array $images = []): void
	{
		$pdf=new \TCPDF('L','mm','A4',true,'UTF-8',false);
		$pdf->SetCreator('SICRAT'); $pdf->SetAuthor($context['generator']); $pdf->SetTitle($title);
		$pdf->SetHeaderData('',0,$title,'SICRAT · '.date('Y-m-d H:i').' · '.$context['generator']);
		$pdf->setFooterData(); $pdf->SetMargins(10,30,10); $pdf->SetAutoPageBreak(true,15); $pdf->SetFont('dejavusans','',8); $pdf->AddPage();
		$pdf->MultiCell(0,10,'Filtros: '.Values::json($context['filters']),0,'L',false,1);
		foreach ($images as $image) {
			// Sólo rutas privadas validadas por el módulo, nunca URL ni SVG recibido del navegador.
			if (!is_string($image) || !is_file($image) || !in_array((new \finfo(FILEINFO_MIME_TYPE))->file($image),['image/png','image/jpeg','image/webp'],true)) throw new \InvalidArgumentException('Imagen PDF inválida.');
			$pdf->Image($image,'','',100); $pdf->Ln(55);
		}
		$width=277/count($columns); $header=function () use ($pdf,$columns,$width) { $pdf->SetFillColor(230,235,240); foreach ($columns as $column) $pdf->MultiCell($width,8,(string) $column,1,'L',true,0); $pdf->Ln(8); };
		$header();
		foreach ($rows as $row) {
			$height=8; foreach ($row as $value) $height=max($height,$pdf->getStringHeight($width,(string) ($value ?? '')));
			if ($pdf->GetY()+$height>195) { $pdf->AddPage(); $header(); }
			$x=$pdf->GetX(); $y=$pdf->GetY();
			foreach ($row as $index=>$value) { $pdf->SetXY($x+$index*$width,$y); $pdf->MultiCell($width,$height,(string) ($value ?? ''),1,'L',false,0); }
			$pdf->SetXY($x,$y+$height);
		}
		$pdf->Output($path,'F'); chmod($path,0600);
	}
}
