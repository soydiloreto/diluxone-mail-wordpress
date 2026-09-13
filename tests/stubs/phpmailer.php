<?php
/**
 * PHPMailer, just enough to see what the transport configures on it.
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
			/** What smtpConnect() should do: true, false, or throw. Set by the tests. */
			public static $connects = true;
			public static int $closed = 0;
			public function __construct(bool $exceptions = false) { $this->smtp = new SMTP(); }
			public function isSMTP(): void { $this->Mailer = 'smtp'; }
			public function smtpConnect(array $options = []): bool {
				if (is_string(self::$connects)) { throw new Exception(self::$connects); }
				if (is_callable($this->Debugoutput)) { ($this->Debugoutput)('SERVER -> CLIENT: 220 ' . $this->Host); }
				return (bool) self::$connects;
			}
			public function smtpClose(): void { ++self::$closed; }
			public function getSMTPInstance(): SMTP { return $this->smtp; }
			public function setFrom(string $a, string $n = '', bool $auto = true): bool { $this->From = $a; $this->FromName = $n; return true; }
		}
	}
}
