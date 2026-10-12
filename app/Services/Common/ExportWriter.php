<?php

declare(strict_types=1);

namespace App\Services\Common;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class ExportWriter
{
	public static function safeCell(mixed $value): mixed
	{
		if (is_string($value) && preg_match('/^[\x00-\x20\x7f]*[=+@-]/u',$value)) return "'".$value;
		return $value;
	}
	public function write(string $format,string $path,array $descriptor,iterable $rows,array $context): void
	{
		if ($format==='pdf') { (new PdfService())->table($path,$descriptor['title'],$descriptor['columns'],$rows,$context); return; }
		if ($format==='xlsx') {
			$writer=new Writer(); $writer->openToFile($path);
			try { $writer->addRow(Row::fromValues($descriptor['columns'])); foreach ($rows as $row) $writer->addRow(Row::fromValues(array_map([self::class,'safeCell'],$row))); }
			finally { $writer->close(); }
		} elseif ($format==='csv') {
			$handle=fopen($path,'wb'); if (!$handle) throw new ServiceFailure('No se pudo escribir CSV.');
			try { fwrite($handle,"\xEF\xBB\xBF"); fputcsv($handle,$descriptor['columns'],',','"',''); foreach ($rows as $row) fputcsv($handle,array_map([self::class,'safeCell'],$row),',','"',''); }
			finally { fclose($handle); }
		} else throw new \InvalidArgumentException('Formato inválido.');
		chmod($path,0600);
	}
}
