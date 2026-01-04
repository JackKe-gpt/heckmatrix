<?php
// access_token.php
// Simple token manager with file caching.
// Change $env to 'production' when ready.

class MpesaToken {
    public $consumer_key  = 'R5LMiuEVhbDR3QyyTd1g6iouHNxtF09GIBNb5DRUSWTIqVjp';
    public $consumer_secret = 'MxxLKCBX2opR5AnXrosCKIN4cKDJz8ovHpfNBocMf1ny6kLWrhFC4ILiyoVHRETY';
    public $env = 'production'; // 'production' for live

    private function endpoint() {
        if ($this->env === 'production') {
            return 'https://api.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
        }
        return 'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials';
    }

    public function getToken() {
        $cacheFile = __DIR__ . '/.token_cache.json';
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true);
            if ($data && isset($data['expires_at']) && $data['expires_at'] > time()) {
                return $data['access_token'];
            }
        }

        $credentials = base64_encode($this->consumer_key . ':' . $this->consumer_secret);
        $ch = curl_init($this->endpoint());
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . $credentials
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $resp = curl_exec($ch);
        if (curl_errno($ch)) {
            error_log('MpesaToken curl error: ' . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);

        $json = json_decode($resp, true);
        if (isset($json['access_token'])) {
            $expires = $json['expires_in'] ?? 3599;
            file_put_contents($cacheFile, json_encode([
                'access_token' => $json['access_token'],
                'expires_at' => time() + $expires - 30
            ]));
            return $json['access_token'];
        }

        error_log('Invalid token response: ' . $resp);
        return null;
    }
}
