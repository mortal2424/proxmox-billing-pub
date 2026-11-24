<?php

function transferBackupFtps(string $host, string $user, string $password, string $directory = '/', ?string $local = null): void
{
    if (!preg_match('/^[A-Za-z0-9.-]+(?::[0-9]{1,5})?$/D', $host) ||
        preg_match('/[\x00-\x1f\x7f]/', $directory) || $user === '' || $password === '') {
        throw new InvalidArgumentException('Проверьте адрес FTPS, каталог и учётные данные');
    }
    $segments = $local === null ? [] : explode('/', trim($directory, '/'));
    if (array_intersect(['.','..'], $segments)) throw new InvalidArgumentException('Некорректный каталог FTPS');
    if ($local !== null) $segments[] = basename($local);
    $url = 'ftp://' . $host . '/' . implode('/', array_map('rawurlencode', $segments));
    $curl = curl_init($url);
    $file = null;
    try {
        curl_setopt_array($curl, [
            CURLOPT_PROTOCOLS=>CURLPROTO_FTP, CURLOPT_USE_SSL=>CURLUSESSL_ALL,
            CURLOPT_FTPSSLAUTH=>CURLFTPAUTH_TLS, CURLOPT_FTP_SKIP_PASV_IP=>true,
            CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
            CURLOPT_FOLLOWLOCATION=>false, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>300,
            CURLOPT_USERNAME=>$user, CURLOPT_PASSWORD=>$password, CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_NOBODY=>$local === null,
        ]);
        if ($local !== null) {
            $file = fopen($local, 'rb');
            if (!$file) throw new RuntimeException('Архив недоступен');
            curl_setopt_array($curl, [CURLOPT_UPLOAD=>true, CURLOPT_INFILE=>$file,
                CURLOPT_INFILESIZE=>filesize($local), CURLOPT_FTP_CREATE_MISSING_DIRS=>CURLFTP_CREATE_DIR_RETRY]);
        }
        if (curl_exec($curl) === false) {
            throw new RuntimeException('Не удалось выполнить FTPS-запрос с проверкой сертификата');
        }
    } finally {
        if (is_resource($file)) fclose($file);
        curl_close($curl);
    }
}
