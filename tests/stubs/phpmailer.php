<?php
/**
 * PHPMailer, lo justo para ver qué le configura el transporte.
 */
namespace PHPMailer\PHPMailer {
	if (!class_exists('PHPMailer\PHPMailer\Exception')) {
		class Exception extends \RuntimeException {}
	}
	if (!class_exists('PHPMailer\PHPMailer\SMTP')) {
		class SMTP { public string $last_reply = '250 OK queued as test-id'; public function getLastReply(): string { return $this->last_reply; } }
	}
	if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
		class PHPMailer {
			public string $Mailer = 'mail'; public string $Host = 'localhost'; public int $Port = 25; public string $SMTPSecure = '';
			public bool $SMTPAutoTLS = true; public bool $SMTPAuth = false; public string $Username = ''; public string $Password = '';
			public int $Timeout = 300; public int $SMTPDebug = 0; public $Debugoutput = 'echo'; public string $MessageID = '';
			public string $From = 'wordpress@localhost'; public string $FromName = 'WordPress';
			private SMTP $smtp;
			public function __construct() { $this->smtp = new SMTP(); }
			public function isSMTP(): void { $this->Mailer = 'smtp'; }
			public function getSMTPInstance(): SMTP { return $this->smtp; }
			public function setFrom(string $a, string $n = '', bool $auto = true): bool { $this->From = $a; $this->FromName = $n; return true; }
		}
	}
}
