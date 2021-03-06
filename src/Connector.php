<?php

namespace Bricksite\RRPProxy;

class Connector
{
    public $username, $password, $apiUrl, $test = false;
    protected $retry = false;

    /**
     * All domain related commands which should convert domain names to idn
     *
     * @var array
     */
    public $domainIDNCommands = ['AddDomain', 'ModifyDomain', 'RenewDomain', 'TransferDomain', 'StatusDomain', 'DeleteDomain', 'PushDomain'];

    /**
     * All dns related commands which should convert domain names to idn
     *
     * @var array
     */
    public $dnsIDNCommands = ['AddDNSZone', 'ModifyDNSZone', 'QueryDNSZoneRRList'];

    public $curlOpts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADER => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Expect:',
            'Content-type: text/html; charset=UTF-8'
        ]
    ];

    public function __construct(string $username = '', string $password = '', bool $test = false)
    {
        if ($username) $this->setUsername($username);
        if ($password) $this->setPassword($password);
        if ($test) $this->setTestMode($test);
    }

    public function setUsername(string $username)
    {
        $this->username = $username;
    }

    public function setPassword(string $password)
    {
        $this->password = $password;
    }

    public function setTestMode(bool $enable)
    {
        $this->test = $enable;
    }

    public function formatDomainArg(string $command, array $args = [])
    {
        if (function_exists('idn_to_ascii')) {
            // IDN Conversion
            if (in_array($command, $this->domainIDNCommands)) {
                $idn = idn_to_ascii($args['domain'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                $args['domain'] = $idn ? $idn : $args['domain'];
            } elseif (in_array($command, $this->dnsIDNCommands)) {
                $idn = idn_to_ascii($args['dnszone'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                $args['dnszone'] = $idn ? $idn : $args['dnszone'];
            }
        }

        return $args;
    }

    public function validateResult($result, string $command, array $args = [])
    {
        if ((preg_match('/^2/', $result['code']))) { // Successful Return Codes (2xx), return the results.
            $this->retry = false;
            return $result;
        } elseif ((preg_match('/^4/', $result['code'])) && !$this->retry) { // Temporary Error Codes (4xx), we do a retry .
            $this->retry = true;
            sleep(5);
            return $this->request($command, $args);
        } else { // Permanent Error Codes (5xx), throw exception.
            throw new \Exception($result['code'] . ' : ' . $result['description']);
        }
    }

    public function request(string $command, $args = [])
    {
        $args = $this->formatDomainArg($command, $args);

        // Inject auth, command and urlencode args
        $requestArgs = array_merge([
            's_login' => rawurlencode($this->username),
            's_pw' => rawurlencode($this->password),
            'command' => rawurlencode($command)
        ], array_map(function ($data) {
            return $data;
        }, $args));
        
        // Build url with get parameters
        $url = ($this->test ? 'https://api-ote.rrpproxy.net/api/call?s_opmode=OTE&' : 'https://api.rrpproxy.net/api/call?') . http_build_query($requestArgs);

        // Send request
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HEADER => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => [
                'Expect:',
                'Content-type: text/html; charset=UTF-8'
            ]
        ]);
        $response = curl_exec($ch);

        if (curl_error($ch)) {
            curl_close($ch);
            throw new \Exception('Curl Error(' . curl_errno($ch) . '): ' . curl_error($ch));
        }
        curl_close($ch);

        $result = $this->processResponse($response);

        return $this->validateResult($result, $command, $requestArgs);
    }

    private function processResponse($response)
    {
        if (is_array($response)) {
            return $response;
        }

        if (empty($response)) {
            throw new \Exception('Empty response from API');
        }

        $hash = array("property" => array());
        $rlist = explode("\n", $response);
        foreach ($rlist as $item) {
            if (preg_match("/^([^\\=]*[^\t\\= ])[\t ]*=[\t ]*(.*)\$/", $item, $m)) {
                list(, $attr, $value) = $m;
                $value = preg_replace("/[\t ]*\$/", "", $value);
                if (preg_match("/^PROPERTY\\[([^\\]]*)\\]/i", $attr, $m)) {
                    $prop = strtolower($m[1]);
                    $prop = preg_replace("/\\s/", "", $prop);
                    if (in_array($prop, array_keys($hash["property"]))) {
                        array_push($hash["property"][$prop], $value);
                    } else {
                        $hash["property"][$prop] = array($value);
                    }
                } else {
                    $hash[$attr] = $value;
                }
            }
        }
        if (is_array($hash['property']) && count($hash['property']) === 0) {
            unset($hash['property']);
        }
        return $hash;
    }
}
