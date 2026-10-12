<?php

declare(strict_types=1);

namespace App\Services\Common;

class WhatsAppProvider implements NotificationProvider
{
	public function assertReady(): void
	{
		if (!env('WHATSAPP_TOKEN') || !ctype_digit((string) env('WHATSAPP_PHONE_NUMBER_ID','')) || !ctype_digit((string) env('WHATSAPP_BUSINESS_ACCOUNT_ID','')) || !preg_match('/^v\d+\.\d+$/',(string) env('WHATSAPP_API_VERSION','')) || rtrim((string) env('WHATSAPP_API_BASE','https://graph.facebook.com'),'/')!=='https://graph.facebook.com') throw new ServiceFailure('WhatsApp Cloud API no está configurado.',false);
	}
	public function send(array $delivery): string
	{
		$this->assertReady();
		$token=(string) env('WHATSAPP_TOKEN',''); $phone=(string) env('WHATSAPP_PHONE_NUMBER_ID',''); $business=(string) env('WHATSAPP_BUSINESS_ACCOUNT_ID','');
		$version=(string) env('WHATSAPP_API_VERSION',''); $base=rtrim((string) env('WHATSAPP_API_BASE','https://graph.facebook.com'),'/');
		if ($token==='' || !ctype_digit($phone) || !ctype_digit($business) || !preg_match('/^v\d+\.\d+$/',$version) || $base!=='https://graph.facebook.com') throw new ServiceFailure('WhatsApp Cloud API no está configurado.',false);
		$template=['name'=>$delivery['plantilla'],'language'=>['code'=>$delivery['contenido']['language']]];
		if ($delivery['contenido']['parameters']!==[]) $template['components']=[['type'=>'body','parameters'=>array_map(fn ($text)=>['type'=>'text','text'=>$text],$delivery['contenido']['parameters'])]];
		$curl=curl_init($base.'/'.$version.'/'.$phone.'/messages');
		curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$token,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>Values::json(['messaging_product'=>'whatsapp','to'=>$delivery['destinatario'],'type'=>'template','template'=>$template])]);
		$response=curl_exec($curl); $status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE); $decoded=is_string($response) ? json_decode($response,true) : null;
		if ($status<200 || $status>=300 || !is_string($decoded['messages'][0]['id'] ?? null)) throw new ServiceFailure('WhatsApp no confirmó el envío. Revisar el proveedor antes de reenviar.',false);
		return Values::text($decoded['messages'][0]['id'],190);
	}
}
