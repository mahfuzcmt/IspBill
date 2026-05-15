<?php
/**
 * SMS dispatcher for NetPulse.
 *
 * Reads provider credentials from tbl_appconfig (sms_* settings) and ships
 * via a simple POST. Designed against the BulkSMSBD-style API:
 *   POST {api_url}
 *     api_key=<key>
 *     senderid=<sender id>
 *     number=<msisdn>
 *     message=<utf-8 text>
 *     type=text
 */
class SmsSender
{
    /**
     * Render a template by replacing {placeholder} tokens with values from $vars.
     */
    public static function render($template, array $vars)
    {
        $out = (string) $template;
        foreach ($vars as $k => $v) {
            $out = str_replace('{' . $k . '}', (string) $v, $out);
        }
        return $out;
    }

    /**
     * Normalise a phone number to international format (e.g. 8801XXXXXXXXX).
     */
    public static function normalizeNumber($number)
    {
        $n = preg_replace('/\D+/', '', (string) $number);
        if ($n === '') return '';
        // Already 880... → return as-is
        if (strpos($n, '880') === 0) return $n;
        // Starts with 0 → drop and prefix 880 (BD convention)
        if (substr($n, 0, 1) === '0' && strlen($n) >= 10) return '88' . $n;
        // Already 11 digits without leading 0 (rare) → assume BD, add 880
        if (strlen($n) === 10 && substr($n, 0, 1) === '1') return '880' . $n;
        return $n;
    }

    /**
     * Send one SMS. Returns an array {ok, http, body, error}.
     */
    public static function send($to, $message)
    {
        global $config;
        $out = ['ok' => false, 'http' => 0, 'body' => '', 'error' => ''];

        if (empty($config['sms_enabled']) || $config['sms_enabled'] === '0') {
            $out['error'] = 'SMS disabled in settings'; return $out;
        }
        if (empty($config['sms_api_key'])) {
            $out['error'] = 'sms_api_key not set'; return $out;
        }
        $url      = !empty($config['sms_api_url']) ? $config['sms_api_url'] : 'https://bulksmsbd.net/api/smsapi';
        $senderId = isset($config['sms_sender_id']) ? $config['sms_sender_id'] : '';
        $msisdn   = self::normalizeNumber($to);
        if ($msisdn === '') { $out['error'] = 'Invalid phone number'; return $out; }
        if (trim((string) $message) === '') { $out['error'] = 'Empty message'; return $out; }

        $params = [
            'api_key'  => $config['sms_api_key'],
            'senderid' => $senderId,
            'number'   => $msisdn,
            'message'  => $message,
            'type'     => 'text',
        ];

        // Use cURL when the extension is available (more reliable), otherwise
        // fall back to the PHP stream wrapper so this works in containers
        // that don't ship php-curl.
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query($params),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT      => 'NetPulse/1.0 (php)',
            ]);
            $body = curl_exec($ch);
            $out['http']  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $out['body']  = is_string($body) ? substr($body, 0, 500) : '';
            $out['error'] = curl_error($ch);
            curl_close($ch);
        } else {
            $ctx = stream_context_create([
                'http' => [
                    'method'        => 'POST',
                    'header'        => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: NetPulse/1.0 (php)\r\n",
                    'content'       => http_build_query($params),
                    'timeout'       => 20,
                    'ignore_errors' => true, // capture body even on 4xx/5xx
                ],
                'ssl' => [
                    'verify_peer'      => false,
                    'verify_peer_name' => false,
                ],
            ]);
            $body = @file_get_contents($url, false, $ctx);
            $out['body']  = is_string($body) ? substr($body, 0, 500) : '';
            $out['http']  = 0;
            $out['error'] = '';
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $h) {
                    if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $h, $m)) {
                        $out['http'] = (int) $m[1];
                        break;
                    }
                }
            }
            if ($body === false) {
                $err = error_get_last();
                $out['error'] = $err && isset($err['message']) ? $err['message'] : 'stream POST failed';
            }
        }

        // BulkSMSBD returns a JSON body with {"response_code": 202, "message_id": ...}
        // 202 = accepted by gateway. HTTP 200 + non-error means success.
        $ok = $out['http'] >= 200 && $out['http'] < 300 && $out['error'] === '';
        // Some gateways return 200 even on logical errors; spot-check the body
        if ($ok && stripos($out['body'], 'error') !== false && stripos($out['body'], '202') === false) {
            $ok = false;
            if ($out['error'] === '') $out['error'] = 'Gateway reported error: ' . substr($out['body'], 0, 200);
        }
        $out['ok'] = $ok;

        // Audit log (best-effort — function may not be loaded in cron context)
        if (function_exists('_log')) {
            _log("SMS to $msisdn " . ($ok ? 'OK' : 'FAIL ' . $out['error']), 'System', 0);
        }
        return $out;
    }

    /**
     * Convenience: render + send.
     */
    public static function sendTemplate($to, $templateKey, array $vars)
    {
        global $config;
        $tpl = isset($config[$templateKey]) ? $config[$templateKey] : '';
        if ($tpl === '') {
            return ['ok' => false, 'http' => 0, 'body' => '', 'error' => "template '$templateKey' not configured"];
        }
        return self::send($to, self::render($tpl, $vars));
    }
}
