<?php
class CorporateNAS
{
    private $nas_ip;
    private $nas_port;
    private $username;
    private $password;
    private $sid;
    private $synotoken;

    public function __construct($nas_ip, $nas_port, $username, $password)
    {
        $this->nas_ip   = $nas_ip;
        $this->nas_port = $nas_port;
        $this->username = $username;
        $this->password = $password;
    }

    private function curlBase()
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        return $ch;
    }

    public function login()
    {
        $url    = "https://{$this->nas_ip}:{$this->nas_port}/webapi/auth.cgi";
        $params = [
            'api'     => 'SYNO.API.Auth',
            'method'  => 'login',
            'version' => 6,
            'account' => $this->username,
            'passwd'  => $this->password,
            'session' => 'FileStation',
            'format'  => 'sid',
        ];

        $ch = $this->curlBase();
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params));
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        if (isset($data['data']['sid'])) {
            $this->sid       = $data['data']['sid'];
            $this->synotoken = isset($data['data']['synotoken']) ? $data['data']['synotoken'] : '';
            return true;
        }
        return false;
    }

    public function upload($localPath, $remoteFolder, $fileName = null)
    {
        if (!$this->sid) $this->login();

        $url = "https://{$this->nas_ip}:{$this->nas_port}/webapi/entry.cgi"
             . "?api=SYNO.FileStation.Upload&version=2&method=upload&_sid=" . urlencode($this->sid);

        $ch = $this->curlBase();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_SAFE_UPLOAD, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'path'           => $remoteFolder,
            'create_parents' => 'true',
            'overwrite'      => 'true',
            'file'           => new CURLFile($localPath, mime_content_type($localPath), $fileName ? $fileName : basename($localPath)),
        ]);
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        if (!empty($data['success'])) {
            return rtrim($remoteFolder, '/') . '/' . $data['data']['file'];
        }
        return false;
    }

    public function download($nasPath)
    {
        if (!$this->sid) $this->login();

        $url = "https://{$this->nas_ip}:{$this->nas_port}/webapi/entry.cgi?"
             . http_build_query([
                 'api'     => 'SYNO.FileStation.Download',
                 'method'  => 'download',
                 'version' => 2,
                 '_sid'    => $this->sid,
                 'path'    => $nasPath,
                 'mode'    => 'download',
             ]);

        $ch = $this->curlBase();
        curl_setopt($ch, CURLOPT_URL, $url);
        $content  = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && $content !== false && strlen($content) > 0) {
            return $content;
        }
        return false;
    }

    public function delete($nasPath, $recycle = false)
    {
        if (!$this->sid) $this->login();

        $url    = "https://{$this->nas_ip}:{$this->nas_port}/webapi/entry.cgi";
        $params = [
            'api'     => 'SYNO.FileStation.Delete',
            'method'  => 'delete',
            'version' => 2,
            '_sid'    => $this->sid,
            'path'    => $nasPath,
            'force'   => 'true',
            'recycle' => $recycle ? 'true' : 'false',
        ];

        $ch = $this->curlBase();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        return !empty($data['success']);
    }
}
