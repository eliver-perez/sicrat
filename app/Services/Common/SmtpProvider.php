<?php

declare(strict_types=1);

namespace App\Services\Common;

use PHPMailer\PHPMailer\PHPMailer;

class SmtpProvider implements NotificationProvider
{
	public function assertReady(): void
	{
		if (!env('SMTP_HOST') || !env('SMTP_FROM')) throw new ServiceFailure('Proveedor de correo no configurado.',false);
	}
	public function send(array $delivery): string
	{
		$this->assertReady();
		$mail=new PHPMailer(true); $mail->isSMTP(); $mail->SMTPDebug=0; $mail->Timeout=30;
		$mail->Host=(string) env('SMTP_HOST'); $mail->Port=Values::integer(env('SMTP_PORT',587),1,65535);
		$mail->SMTPAuth=env('SMTP_USERNAME','')!==''; $mail->Username=(string) env('SMTP_USERNAME',''); $mail->Password=(string) env('SMTP_PASSWORD','');
		$mail->SMTPSecure=env('SMTP_TLS','starttls')==='smtps' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
		$mail->CharSet='UTF-8'; $mail->setFrom((string) env('SMTP_FROM'),(string) env('SMTP_FROM_NAME','SICRAT'));
		$mail->addAddress($delivery['destinatario']);
		$mail->Subject=$delivery['contenido']['title']; $mail->Body=$delivery['contenido']['summary']; $mail->isHTML(false);
		// Message-ID estable facilita reconciliación. SMTP no ofrece entrega exactamente una vez.
		$mail->MessageID='<sicrat-'.$delivery['id'].'@'.substr(strrchr((string) env('SMTP_FROM'),'@'),1).'>';
		try { $mail->send(); return mb_substr($mail->getLastMessageID(),0,190); }
		catch (\Throwable) { throw new ServiceFailure('El proveedor SMTP rechazó o no confirmó el envío. Revisar antes de reenviar.',false); }
	}
}
