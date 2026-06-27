<?php

class SmtpMailer
{
    private array $config;
    private string $lastError = '';

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $textBody): bool
    {
        $this->lastError = '';
        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) $this->config['port'];
        $encryption = strtolower((string) ($this->config['encryption'] ?? 'ssl'));
        $username = trim((string) ($this->config['username'] ?? ''));
        $password = (string) ($this->config['password'] ?? '');

        if ($host === '' || $username === '' || $password === '') {
            $this->lastError = 'SMTP is not fully configured.';
            return false;
        }

        $scheme = $encryption === 'ssl' ? 'ssl://' : '';
        $socket = @fsockopen($scheme . $host, $port, $errno, $errstr, 10);

        if (!$socket) {
            $this->lastError = 'Could not connect to mail server: ' . ($errstr !== '' ? $errstr : 'connection failed') . '.';
            return false;
        }

        stream_set_timeout($socket, 10);

        try {
            $this->expect($socket, [220]);
            $this->command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);

            if ($encryption === 'tls' || $encryption === 'starttls') {
                // Hostinger can also run on STARTTLS; upgrade the socket before authenticating.
                $this->command($socket, 'STARTTLS', [220]);

                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('Could not start secure mail connection.');
                }

                $this->command($socket, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? 'localhost'), [250]);
            }

            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode($username), [334]);
            $this->command($socket, base64_encode($password), [235]);
            $this->command($socket, 'MAIL FROM:<' . $this->config['from_email'] . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            // Build a multipart email so credentials are readable in any mail client.
            $boundary = 'kiwi_' . bin2hex(random_bytes(12));
            $headers = [
                'From: ' . $this->formatAddress($this->config['from_email'], $this->config['from_name']),
                'To: ' . $this->formatAddress($toEmail, $toName),
                'Subject: ' . $this->encodeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            ];
            $message = implode("\r\n", $headers)
                . "\r\n\r\n--{$boundary}\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $textBody
                . "\r\n\r\n--{$boundary}\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $htmlBody
                . "\r\n\r\n--{$boundary}--\r\n.";

            $this->command($socket, $message, [250]);
            $this->command($socket, 'QUIT', [221]);
            fclose($socket);

            return true;
        } catch (RuntimeException $exception) {
            fclose($socket);
            $this->lastError = $exception->getMessage();
            return false;
        }
    }

    private function command($socket, string $command, array $expectedCodes): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->expect($socket, $expectedCodes);
    }

    private function expect($socket, array $expectedCodes): string
    {
        $response = '';

        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;

            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }

        $code = (int) substr($response, 0, 3);

        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('Mail server rejected the message: ' . trim($response));
        }

        return $response;
    }

    private function formatAddress(string $email, string $name): string
    {
        return $this->encodeHeader($name) . ' <' . $email . '>';
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
