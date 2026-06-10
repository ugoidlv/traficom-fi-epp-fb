<?php
/**
 * FOSSBilling .FI EPP Registrar Adapter by UGO.ID.LV
 *
 * @copyright UGO.ID.LV
 * @license   MIT
 * @version   1.0.0
 * @link      https://github.com/ugoidlv/traficom-fi-epp-fb
 */

class Registrar_Adapter_TraficomFiEppFb extends Registrar_Adapter_Abstract
{
    // EPP XML-Namespaces für Traficom (.fi)
    private $ns_epp     = 'urn:ietf:params:xml:ns:epp-1.0';
    private $ns_domain  = 'urn:ietf:params:xml:ns:domain-1.0';
    private $ns_contact = 'urn:ietf:params:xml:ns:contact-1.0';
    private $ns_ficora  = 'urn:ietf:params:xml:ns:ficora-1.0';

    /**
     * Konfigurationsoberfläche im FOSSBilling Admin-Panel
     */
    public function config()
    {
        return [
            'label' => 'UGO.ID.LV :FI EPP Registrar',
            'form'  => [
                'host' => ['text', [
                    'label' => 'Traficom EPP Host (z.B. epp.test.domain.fi oder epp.domain.fi)',
                    'required' => true,
                ]],
                'port' => ['text', [
                    'label' => 'EPP Port (Standard: 700)',
                    'required' => true,
                    'value' => '700',
                ]],
                'username' => ['text', [
                    'label' => 'EPP User ID (Registrar ID)',
                    'required' => true,
                ]],
                'password' => ['password', [
                    'label' => 'EPP Passwort',
                    'required' => true,
                ]],
                'cert_path' => ['text', [
                    'label' => 'Absoluter Pfad zum TLS-Zertifikat (.pem)',
                    'required' => true,
                    'description' => 'Wird für die Client-Authentifizierung bei Traficom benötigt.',
                ]],
                'key_path' => ['text', [
                    'label' => 'Absoluter Pfad zum Private Key (.key)',
                    'required' => true,
                ]],
            ]
        ];
    }

    /**
     * Kern-Methode: Baut die verschlüsselte TCP/TLS-Verbindung zu Traficom auf und loggt sich ein
     */
    private function _sendEppCommand($xmlRequest)
    {
        $api  = $this->getApi();
        $host = $api->getKeyValue('host');
        $port = $api->getKeyValue('port');
        $cert = $api->getKeyValue('cert_path');
        $key  = $api->getKeyValue('key_path');

        $context = stream_context_create([
            'ssl' => [
                'local_cert'        => $cert,
                'local_pk'          => $key,
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false
            ]
        ]);

        $target = "tls://{$host}:{$port}";
        $socket = @stream_socket_client($target, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            throw new Registrar_Exception("Verbindung fehlgeschlagen ({$errno}): {$errstr}");
        }

        stream_set_timeout($socket, 20);

        // 1. Greeting einlesen
        $this->_readFrame($socket);

        // 2. Login-XML senden
        $clTRID = 'UGO-' . time() . '-' . rand(1000, 9990);
        $loginXml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>
        <epp xmlns=\"{$this->ns_epp}\">
          <command>
            <login>
              <clID>" . htmlspecialchars($api->getKeyValue('username')) . "</clID>
              <pw>" . htmlspecialchars($api->getKeyValue('password')) . "</pw>
              <options>
                <version>1.0</version>
                <lang>en</lang>
              </options>
              <svcs>
                <objURI>{$this->ns_domain}</objURI>
                <objURI>{$this->ns_contact}</objURI>
              </svcs>
            </login>
            <clTRID>{$clTRID}</clTRID>
          </command>
        </epp>";

        $this->_writeFrame($socket, $loginXml);
        $loginResponse = $this->_readFrame($socket);
        
        if (strpos($loginResponse, 'result code="1000"') === false) {
            @fclose($socket);
            throw new Registrar_Exception("Login fehlgeschlagen. Bitte Zugangsdaten prüfen.");
        }

        // 3. Das eigentliche EPP-Kommando senden
        $this->_writeFrame($socket, $xmlRequest);
        $actualResponse = $this->_readFrame($socket);

        // 4. Logout
        $logoutXml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>
        <epp xmlns=\"{$this->ns_epp}\">
          <command>
            <logout/>
            <clTRID>{$clTRID}-out</clTRID>
          </command>
        </epp>";
        $this->_writeFrame($socket, $logoutXml);
        @fclose($socket);

        return $actualResponse;
    }

    private function _writeFrame($socket, $xml)
    {
        $length = strlen($xml) + 4;
        $header = pack('N', $length);
        fwrite($socket, $header . $xml);
    }

    private function _readFrame($socket)
    {
        $header = fread($socket, 4);
        if (empty($header)) return '';
        $unpacked = unpack('Nlength', $header);
        $length = $unpacked['length'] - 4;
        
        $response = '';
        while (strlen($response) < $length) {
            $buffer = fread($socket, min($length - strlen($response), 8192));
            if (empty($buffer)) break;
            $response .= $buffer;
        }
        return $response;
    }

    /**
     * Registriert eine neue .FI Domain
     */
    public function registerDomain(Registrar_Domain $domain)
    {
        $client = $domain->getContactRegistrar();
        $clTRID = 'UGO-REG-' . time();
        $contactId = 'FI-' . strtoupper(substr(md5($client->getEmail()), 0, 10));

        $identity = $client->getCompanyNumber() ? $client->getCompanyNumber() : 'N/A';

        // Schritt A: Kontakt anlegen
        $contactXml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>
        <epp xmlns=\"{$this->ns_epp}\">
          <command>
            <create>
              <contact:create xmlns:contact=\"{$this->ns_contact}\">
                <contact:id>{$contactId}</contact:id>
                <contact:postalInfo type=\"int\">
                  <contact:name>" . htmlspecialchars($client->getName()) . "</contact:name>
                  <contact:org>" . htmlspecialchars($client->getCompany()) . "</contact:org>
                  <contact:addr>
                    <contact:street>" . htmlspecialchars($client->getAddress1()) . "</contact:street>
                    <contact:city>" . htmlspecialchars($client->getCity()) . "</contact:city>
                    <contact:pc>" . htmlspecialchars($client->getZip()) . "</contact:pc>
                    <contact:cc>" . htmlspecialchars($client->getCountry()) . "</contact:cc>
                  </contact:addr>
                </contact:postalInfo>
                <contact:voice>" . htmlspecialchars($client->getTel()) . "</contact:voice>
                <contact:email>" . htmlspecialchars($client->getEmail()) . "</contact:email>
                <contact:authInfo><contact:pw>Ugo_SecureP@ss1</contact:pw></contact:authInfo>
              </contact:create>
            </create>
            <extension>
              <ficora:contact xmlns:ficora=\"{$this->ns_ficora}\">
                <ficora:identity>{$identity}</ficora:identity>
              </ficora:contact>
            </extension>
            <clTRID>{$clTRID}-c</clTRID>
          </command>
        </epp>";

        try { $this->_sendEppCommand($contactXml); } catch(\Exception $e) {}

        // Schritt B: Domain anlegen
        $authInfo = bin2hex(random_bytes(10));
        $domainXml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>
        <epp xmlns=\"{$this->ns_epp}\">
          <command>
            <create>
              <domain:create xmlns:domain=\"{$this->ns_domain}\">
                <domain:name>" . htmlspecialchars($domain->getName()) . "</domain:name>
                <domain:period unit=\"y\">" . intval($domain->getPeriod()) . "</domain:period>";
                
        if ($domain->getNs1() || $domain->getNs2()) {
            $domainXml .= "<domain:ns>";
            if ($domain->getNs1()) $domainXml .= "<domain:hostObj>" . htmlspecialchars($domain->getNs1()) . "</domain:hostObj>";
            if ($domain->getNs2()) $domainXml .= "<domain:hostObj>" . htmlspecialchars($domain->getNs2()) . "</domain:hostObj>";
            $domainXml .= "</domain:ns>";
        }

        $domainXml .= " <domain:registrant>{$contactId}</domain:registrant>
                <domain:authInfo><domain:pw>{$authInfo}</domain:pw></domain:authInfo>
              </domain:create>
            </create>
            <clTRID>{$clTRID}-d</clTRID>
          </command>
        </epp>";

        $res = $this->_sendEppCommand($domainXml);
        if (strpos($res, 'result code="1000"') !== false) {
            return true;
        }
        throw new Registrar_Exception("Domain-Registrierung fehlgeschlagen: " . $res);
    }

    /**
     * .FI Transfer
     */
    public function transferDomain(Registrar_Domain $domain)
    {
        $clTRID = 'UGO-TR-' . time();
        $authCode = $domain->getEppId();

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>
        <epp xmlns=\"{$this->ns_epp}\">
          <command>
            <transfer op=\"request\">
              <domain:transfer xmlns:domain=\"{$this->ns_domain}\">
                <domain:name>" . htmlspecialchars($domain->getName()) . "</domain:name>
                <domain:authInfo><domain:pw>" . htmlspecialchars($authCode) . "</domain:pw></domain:authInfo>
              </domain:transfer>
            </transfer>
            <clTRID>{$clTRID}</clTRID>
          </command>
        </epp>";

        $res = $this->_sendEppCommand($xml);
        if (strpos($res, 'result code="1000"') !== false || strpos($res, 'result code="1001"') !== false) {
            return true;
        }
        throw new Registrar_Exception("Transfer fehlgeschlagen: " . $res);
    }

    /**
     * Domain verlängern
     */
    public function renewDomain(Registrar_Domain $domain)
    {
        $clTRID = 'UGO-REN-' . time();
        $currentExpDate = date('Y-m-d', $domain->getExpires());

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>
        <epp xmlns=\"{$this->ns_epp}\">
          <command>
            <renew>
              <domain:renew xmlns:domain=\"{$this->ns_domain}\">
                <domain:name>" . htmlspecialchars($domain->getName()) . "</domain:name>
                <domain:curExpDate>{$currentExpDate}</domain:curExpDate>
                <domain:period unit=\"y\">" . intval($domain->getPeriod()) . "</domain:period>
              </domain:renew>
            </renew>
            <clTRID>{$clTRID}</clTRID>
          </command>
        </epp>";

        $res = $this->_sendEppCommand($xml);
        if (strpos($res, 'result code="1000"') !== false) {
            return true;
        }
        throw new Registrar_Exception("Verlängerung fehlgeschlagen: " . $res);
    }

    /**
     * Domain kündigen / löschen
     */
    public function deleteDomain(Registrar_Domain $domain)
    {
        $clTRID = 'UGO-DEL-' . time();
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>
        <epp xmlns=\"{$this->ns_epp}\">
          <command>
            <delete>
              <domain:delete xmlns:domain=\"{$this->ns_domain}\">
                <domain:name>" . htmlspecialchars($domain->getName()) . "</domain:name>
              </domain:delete>
            </delete>
            <clTRID>{$clTRID}</clTRID>
          </command>
        </epp>";

        $res = $this->_sendEppCommand($xml);
        if (strpos($res, 'result code="1000"') !== false) {
            return true;
        }
        throw new Registrar_Exception("Löschen fehlgeschlagen: " . $res);
    }

    public function isDomainAvailable(Registrar_Domain $domain) { return true; }
    public function modifyNs(Registrar_Domain $domain) { return true; }
    public function modifyContact(Registrar_Domain $domain) { return true; }
    public function getDomainDetails(Registrar_Domain $domain) { return []; }
}
