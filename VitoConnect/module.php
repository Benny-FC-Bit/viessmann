<?php

declare(strict_types=1);

include_once __DIR__ . '/../libs/WebHookModule.php';
include_once __DIR__ . '/compute_name.php';

if (defined('PHPUNIT_TESTSUITE')) {
    trait Simulate
    {
        public function DebugParseDeviceData(object $device)
        {
            $this->failOnUnexpected = true;
            return $this->ParseDeviceData($device);
        }
    }
} else {
    trait Simulate
    {
    }
}

class VitoConnect extends WebHookModule
{
    use Simulate;

    private $authorize_url = 'https://iam.viessmann-climatesolutions.com/idp/v3/authorize';
    private $token_url = 'https://iam.viessmann-climatesolutions.com/idp/v3/token';

    private $installation_data_url = 'https://api.viessmann-climatesolutions.com/iot/v2/equipment/installations?includeGateways=true';
    private $device_data_url = 'https://api.viessmann-climatesolutions.com/iot/v2/features/installations/%s/gateways/%s/devices/0/features/';

    public function __construct($InstanceID)
    {
        parent::__construct($InstanceID, 'viessmann/' . $InstanceID);
    }

    public function Create()
    {
        //Never delete this line!
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');

        $this->RegisterPropertyInteger('Interval', 15);

        $this->RegisterAttributeString('Token', '');

        $this->RegisterAttributeInteger('InstallationID', 0);
        $this->RegisterAttributeString('GatewaySerial', '');

        $this->RegisterTimer('Update', 0, 'VVC_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ZirkulationStop', 0, 'VVC_StopZirkulation($_IPS[\'TARGET\']);');
        $this->RegisterTimer('ZirkulationSperre', 0, 'VVC_ZirkulationSperreAufheben($_IPS[\'TARGET\']);');

        $this->RegisterVariableBoolean('ZirkulationAktiv', 'Zirkulation aktiv', '~Switch', 90);
        $this->RegisterVariableInteger('ZirkulationAnzahl', 'Zirkulation Aktivierungen', '', 91);
        $this->RegisterAttributeInteger('ZirkulationSperreEnde', 0);
    }

    public function ApplyChanges()
    {

        //Never delete this line!
        parent::ApplyChanges();

        //Set Timer only if valid credential are available
        if ($this->ReadAttributeString('Token')) {
            $this->SetTimerInterval('Update', $this->ReadPropertyInteger('Interval') * 60 * 1000);
        } else {
            $this->SetTimerInterval('Update', 0);
        }

        // Zirkulationssperre nach IPS-Neustart aufräumen falls abgelaufen
        $sperreEnde = $this->ReadAttributeInteger('ZirkulationSperreEnde');
        if ($sperreEnde > 0 && time() >= $sperreEnde) {
            $this->ZirkulationSperreAufheben();
        }
    }

    public function Register()
    {
        $base64url_encode = function ($plainText)
        {
            $base64 = base64_encode($plainText);
            $base64 = trim($base64, '=');
            $base64url = strtr($base64, '+/', '-_');
            return $base64url;
        };

        $random = bin2hex(random_bytes(32));
        $this->SetBuffer('Verifier', $base64url_encode(pack('H*', $random)));
        $this->SetBuffer('Challenge', $base64url_encode(pack('H*', hash('sha256', $this->GetBuffer('Verifier')))));

        echo $this->authorize_url . '?client_id=' . urlencode($this->ReadPropertyString('ClientID')) . '&redirect_uri=' . urlencode($this->GetCallbackURL()) . '&response_type=code&code_challenge=' . $this->GetBuffer('Challenge') . '&code_challenge_method=S256&scope=IoT%20User%20offline_access';
    }

    public function GetConfigurationForm()
    {
        $data = json_decode(file_get_contents(__DIR__ . '/form.json'));

        $data->elements[1]->value = $this->GetCallbackURL();

        $data->actions[0]->enabled = strlen($this->ReadPropertyString('ClientID')) > 0;
        $data->actions[1]->enabled = strlen($this->ReadAttributeString('Token')) > 0;

        return json_encode($data);
    }

    public function Update()
    {
        $this->ParseDeviceData($this->RequestDeviceData());
        $this->SetStatus(IS_ACTIVE);
    }

    public function RequestAction($Ident, $Value)
    {
        $parts = explode('_', $Ident);
        $name = array_pop($parts);
        $id = implode('.', $parts);
        switch ($name) {
            case 'active':
                if ($Value) {
                    $this->RequestDeviceData($id . '/activate');
                } else {
                    $this->RequestDeviceData($id . '/deactivate');
                }
                $this->SetValue($Ident, $Value);
                break;
            case 'value':
                if (strpos($Ident, 'modes') !== false) {
                    $this->RequestDeviceData($id . '/setMode', [
                        'mode' => $Value
                    ]);
                    $this->SetValue($Ident, $Value);
                } elseif (strpos($Ident, 'hysteresis') !== false) {
                    $this->RequestDeviceData($id . '/setHysteresis', [
                        'hysteresis' => $Value
                    ]);
                    $this->SetValue($Ident, $Value);
                } elseif (strpos($Ident, 'temperature') !== false) {
                    $this->RequestDeviceData($id . '/setTargetTemperature', [
                        'temperature' => $Value
                    ]);
                    $this->SetValue($Ident, $Value);
                } else {
                    throw new Exception('Invalid Ident');
                }
                break;
            case 'temperature':
                $this->RequestDeviceData($id . '/setTemperature', [
                    'targetTemperature' => $Value
                ]);
                $this->SetValue($Ident, $Value);
                break;
            case 'min':
                $this->RequestDeviceData($id . '/setMin', [
                    'temperature' => $Value
                ]);
                $this->SetValue($Ident, $Value);
                break;
            case 'max':
                $this->RequestDeviceData($id . '/setMax', [
                    'temperature' => $Value
                ]);
                $this->SetValue($Ident, $Value);
                break;
            default:
                throw new Exception('Invalid Ident');
        }
    }

    protected function ProcessHookData()
    {
        $this->SendDebug('GET', print_r($_GET, true), 0);
        $this->SendDebug('POST', file_get_contents('php://input'), 0);

        $this->SendDebug('ExchangeCodeToRefreshToken', '', 0);

        $options = [
            'http' => [
                'header'  => "Content-Type: application/x-www-form-urlencoded;charset=utf-8\r\n",
                'method'  => 'POST',
                'content' => http_build_query([
                    'client_id'     => $this->ReadPropertyString('ClientID'),
                    'code'          => $_GET['code'],
                    'redirect_uri'  => $this->GetCallbackURL(),
                    'grant_type'    => 'authorization_code',
                    'code_verifier' => $this->GetBuffer('Verifier')
                ]),
                'ignore_errors' => true
            ]
        ];
        $context = stream_context_create($options);
        $result = file_get_contents($this->token_url, false, $context);

        $this->SendDebug('RESULT', $result, 0);

        $data = json_decode($result);

        if ($data === null) {
            $this->LogMessage('Ungültige Antwort beim Token-Abruf', KL_ERROR);
            echo 'Invalid response while fetching access token!';
            return;
        }

        if (isset($data->error)) {
            $this->LogMessage('Token-Fehler: ' . $data->error, KL_ERROR);
            echo $data->error;
            return;
        }

        if (!isset($data->token_type) || $data->token_type != 'Bearer') {
            $this->LogMessage('Unerwarteter Token-Typ', KL_ERROR);
            echo 'Bearer Token expected';
            return;
        }

        $this->SendDebug('GotRefreshToken', print_r($data, true), 0);

        $this->WriteAttributeString('Token', $data->refresh_token);
        $this->SetBuffer('Token', $data->access_token);
        $this->SetBuffer('Expires', strval(time() + intval($data->expires_in) - 60));

        $this->Initialize();

        $this->UpdateFormField('Update', 'enabled', true);

        echo $this->Translate("Successful. You can now close this window and press 'Update' inside the instance.");
    }

    private function GetCallbackURL()
    {
        $cc_id = IPS_GetInstanceListByModuleID('{9486D575-BE8C-4ED8-B5B5-20930E26DE6F}')[0];
        $cc_url = @CC_GetConnectURL($cc_id);

        if ($cc_url) {
            return $cc_url . '/hook/viessmann/' . $this->InstanceID;
        }

        return $this->Translate('Symcon Connect must be enabled!');
    }

    private function Initialize()
    {
        //Fetch Installation ID and Gateway Serial for later reuse.
        $installation = $this->FetchData($this->installation_data_url);
        $this->SendDebug('InstallationID', $installation->data[0]->id, 0);
        $this->SendDebug('GatewaySerial', $installation->data[0]->gateways[0]->serial, 0);

        $this->WriteAttributeInteger('InstallationID', $installation->data[0]->id);
        $this->WriteAttributeString('GatewaySerial', $installation->data[0]->gateways[0]->serial);
    }

    private function UpdateAccessToken()
    {

        //Request a new Access Token if required
        $accessToken = $this->GetBuffer('Token');
        if ($accessToken == '' || time() >= intval($this->GetBuffer('Expires'))) {
            $this->SendDebug('UpdateAccessToken', '', 0);

            $options = [
                'http' => [
                    'header'  => "Content-Type: application/x-www-form-urlencoded;charset=utf-8\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query([
                        'client_id'     => $this->ReadPropertyString('ClientID'),
                        'grant_type'    => 'refresh_token',
                        'refresh_token' => $this->ReadAttributeString('Token')
                    ]),
                    'ignore_errors' => true
                ]
            ];
            $context = stream_context_create($options);
            $result = file_get_contents($this->token_url, false, $context);

            $this->SendDebug('RESULT', $result, 0);

            $data = json_decode($result);

            if ($data === null) {
                $this->LogMessage('Ungültige Antwort beim Token-Refresh', KL_ERROR);
                $this->SetStatus(201);
                throw new Exception('Ungültige Antwort beim Token-Refresh');
            }

            if (isset($data->error)) {
                $this->LogMessage('Token-Refresh Fehler: ' . $data->error, KL_ERROR);
                $this->SetStatus(201);
                throw new Exception('Token-Refresh Fehler: ' . $data->error);
            }

            if (!isset($data->token_type) || $data->token_type != 'Bearer') {
                $this->LogMessage('Unerwarteter Token-Typ beim Refresh', KL_ERROR);
                $this->SetStatus(201);
                throw new Exception('Bearer Token erwartet');
            }

            $this->WriteAttributeString('Token', $data->refresh_token);
            $this->SetBuffer('Token', $data->access_token);
            $this->SetBuffer('Expires', strval(time() + intval($data->expires_in) - 60));

            $accessToken = $data->access_token;
        }

        return $accessToken;
    }

    private function FetchData($url)
    {
        $accessToken = $this->UpdateAccessToken();

        //FetchData with Access Token
        $this->SendDebug('FetchData', $url, 0);

        $options = [
            'http' => [
                'header'        => 'Authorization: Bearer ' . $accessToken . "\r\n",
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        // HTTP-Statuscode aus Response-Header auslesen
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                    $httpCode = intval($matches[1]);
                }
            }
        }

        if ($result === false) {
            $this->LogMessage('Datenabruf fehlgeschlagen: ' . $url, KL_ERROR);
            throw new Exception('Datenabruf fehlgeschlagen');
        }

        // Rate-Limit Behandlung
        if ($httpCode == 429) {
            $this->LogMessage('Viessmann API Rate-Limit erreicht (HTTP 429). Nächster Versuch beim nächsten Update-Zyklus.', KL_WARNING);
            $this->SendDebug('RateLimit', 'HTTP 429 - Rate-Limit erreicht', 0);
            throw new Exception('API Rate-Limit erreicht (HTTP 429). Bitte Abfrageintervall erhöhen.');
        }

        if ($httpCode == 401) {
            $this->SendDebug('TokenExpired', 'HTTP 401 - Token ungültig, erzwinge Refresh', 0);
            $this->SetBuffer('Token', '');
            $this->SetBuffer('Expires', '0');
            $this->SetStatus(201);
            throw new Exception('Token ungültig (HTTP 401). Token-Refresh wird beim nächsten Aufruf versucht.');
        }

        $this->SendDebug('GotData', $result, 0);

        $data = json_decode($result);

        if ($data === null) {
            $this->LogMessage('Ungültige API-Antwort', KL_ERROR);
            throw new Exception('Ungültige API-Antwort');
        }

        if (isset($data->error)) {
            $this->LogMessage('API-Fehler: ' . $data->error, KL_ERROR);
            throw new Exception('API-Fehler: ' . $data->error);
        }

        return $data;
    }

    private function SendAction($url, $post_data = null)
    {
        $accessToken = $this->UpdateAccessToken();

        //SendAction with Access Token
        $this->SendDebug('SendAction', $url, 0);

        $options = [
            'http' => [
                'method'        => 'POST',
                'header'        => 'Authorization: Bearer ' . $accessToken . "\r\nContent-Type: application/json\r\nAccept: application/vnd.siren+json\r\n",
                'content'       => ($post_data == null) ? '{}' : json_encode($post_data),
                'ignore_errors' => true
            ]
        ];

        $context = stream_context_create($options);
        $result = file_get_contents($url, false, $context);

        // HTTP-Statuscode auslesen
        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches)) {
                    $httpCode = intval($matches[1]);
                }
            }
        }

        if ($result === false) {
            $this->LogMessage('Aktion fehlgeschlagen: ' . $url, KL_ERROR);
            throw new Exception('Aktion fehlgeschlagen');
        }

        if ($httpCode == 429) {
            $this->LogMessage('API Rate-Limit erreicht bei Aktion (HTTP 429)', KL_WARNING);
            throw new Exception('API Rate-Limit erreicht (HTTP 429)');
        }

        if ($httpCode >= 400) {
            $this->SendDebug('ActionError', 'HTTP ' . $httpCode . ': ' . $result, 0);
            $this->LogMessage('Aktion fehlgeschlagen: HTTP ' . $httpCode, KL_ERROR);
            throw new Exception('Aktion fehlgeschlagen: HTTP ' . $httpCode);
        }

        $this->SendDebug('Success', $result, 0);
    }

    private function RequestDeviceData($action = '', $post_data = null)
    {
        $id = $this->ReadAttributeInteger('InstallationID');
        $serial = $this->ReadAttributeString('GatewaySerial');

        if ($id == 0 || $serial == '') {
            $this->LogMessage('InstallationID oder GatewaySerial fehlen', KL_ERROR);
            $this->SetStatus(202);
            throw new Exception('InstallationID oder GatewaySerial fehlen. Bitte neu authentifizieren.');
        }

        if ($action) {
            return $this->SendAction(sprintf($this->device_data_url, $id, $serial) . $action, $post_data);
        } else {
            return $this->FetchData(sprintf($this->device_data_url, $id, $serial));
        }
    }

    private function ParseDeviceData($device)
    {
        $updateVariable = function ($id, $name, $type, $value, $profile)
        {
            $ident = str_replace('.', '_', $id) . '_' . strtolower($name);
            switch ($type) {
                case 'boolean':
                    $this->RegisterVariableBoolean($ident, computeName($id, $name), $profile);
                    $this->SetValue($ident, $value);
                    break;
                case 'number':
                    $this->RegisterVariableFloat($ident, computeName($id, $name), $profile);
                    $this->SetValue($ident, $value);
                    break;
                case 'string':
                    $this->RegisterVariableString($ident, computeName($id, $name), $profile);
                    $this->SetValue($ident, $value);
                    break;
                case 'array':
                case 'object':
                    $this->RegisterVariableString($ident, computeName($id, $name));
                    $this->SetValue($ident, json_encode($value));
                    break;
                case 'Schedule':
                    // Lets skip this
                    break;
                case '_Time':
                    // This is not a real type. We defined it to enforce a real integer variable
                    $this->RegisterVariableInteger($ident, computeName($id, $name), $profile);
                    $this->SetValue($ident, $value);
                    break;
                default:
                    $this->SendDebug('Unsupported Type', 'type: ' . $type . ', id: ' . $id . ', value: ' . print_r($value, true), 0);
                    $this->LogMessage('Nicht unterstützter Variablentyp: ' . $type . ' für ' . $id, KL_WARNING);
                    return;
            }
        };

        $findCommand = function ($commands, $name)
        {
            foreach ($commands as $command) {
                if ($command->name == $name) {
                    return $command;
                }
            }
            return false;
        };

        $updateAction = function ($id, $name, $commands) use ($findCommand)
        {
            $ident = str_replace('.', '_', $id) . '_' . strtolower($name);
            switch ($name) {
                case 'active':
                    if ($findCommand($commands, 'activate') && $findCommand($commands, 'deactivate')) {
                        $this->EnableAction($ident);
                    }
                    break;
                case 'value':
                    if ($findCommand($commands, 'setMode')) {
                        $this->EnableAction($ident);
                    } elseif ($findCommand($commands, 'setHysteresis')) {
                        $this->EnableAction($ident);
                    } elseif ($findCommand($commands, 'setTargetTemperature')) {
                        $this->EnableAction($ident);
                    }
                    break;
                case 'temperature':
                    if ($findCommand($commands, 'setTemperature')) {
                        $this->EnableAction($ident);
                    }
                    break;
                case 'min':
                    if ($findCommand($commands, 'setMin')) {
                        $this->EnableAction($ident);
                    }
                    break;
                case 'max':
                    if ($findCommand($commands, 'setMax')) {
                        $this->EnableAction($ident);
                    }
                    break;
            }
        };

        //Parse data
        foreach ($device->data as $entity) {
            foreach ($entity->properties as $name => $property) {
                //Convert unit to our profiles
                $unitToProfile = function ($unit)
                {
                    switch ($unit) {
                        case '':
                            return '';
                        case 'bar':
                            return ''; // We currently do not have a profile for bar
                        case 'cubicMeter':
                            return 'Gas';
                        case 'celsius':
                            return 'Temperature.Room';
                        case 'kilowattHour':
                            return 'Electricity';
                        case 'watt':
                            return 'Watt.3680';
                        case 'kilowatt':
                            return 'Power';
                        case 'percent':
                            return 'Valve.F';
                        case 'seconds':
                            return ''; // We currently do not have a profile for seconds
                        default:
                            if (isset($this->failOnUnexpected)) {
                                throw new Exception(sprintf('Unknown unit: %s', $unit));
                            } else {
                                $this->SendDebug('Unknown Unit', $unit, 0);
                            }
                            return '';
                    }
                };

                //Convert name to our profiles
                $nameToProfile = function ($name, $commands) use ($findCommand)
                {
                    switch ($name) {
                        case 'active':
                            return 'Switch';
                        case 'value':
                            $command = $findCommand($commands, 'setMode');
                            if ($command) {
                                return $this->CreateProfile('VVC.Mode', VARIABLETYPE_STRING, $command->params->mode->constraints->enum);
                            } elseif ($findCommand($commands, 'setHysteresis')) {
                                return 'Temperature';
                            } elseif ($findCommand($commands, 'setTargetTemperature')) {
                                return 'Temperature';
                            }
                            return '';
                        case 'temperature':
                            return 'Temperature.Room';
                        default:
                            return '';
                    }
                };

                // If unit is not defined on this level, search if have a global defined unit
                // For now we only need to fix array units. Therefore limit this to array.
                // Maybe we should better read the docs on how to handle this global unit field
                if (!isset($property->unit) && ($property->type == 'array')) {
                    foreach ($entity->properties as $n => $p) {
                        if ($n == 'unit') {
                            $property->unit = $p->value;
                        }
                    }
                }

                switch ($name) {
                    //We want to skip a few fields
                    case 'unit':
                    case 'minUnit':
                    case 'maxUnit':
                        break;
                    case 'dayValueReadAt':
                    case 'weekValueReadAt':
                    case 'monthValueReadAt':
                    case 'yearValueReadAt':
                        $updateVariable($entity->feature, $name, '_Time', $property->value ? strtotime($property->value) : 0, 'UnixTimestamp');
                        break;
                    default:
                        // Deduct profile
                        $profile = '';
                        if (isset($property->unit)) {
                            $profile = $unitToProfile($property->unit);
                        }
                        if (!$profile) {
                            $profile = $nameToProfile($name, $entity->commands);
                        }

                        // Fix up a few array values, which we want to reduce to a single value
                        if ($property->type == 'array') {
                            switch ($profile) {
                                case 'Electricity':
                                case 'Gas':
                                    $property->type = 'number';
                                    if (count($property->value) == 0) {
                                        $property->value = 0;
                                    } else {
                                        $property->value = $property->value[0];
                                    }
                                    break;
                            }
                        }

                        // Create and updates variables
                        $updateVariable($entity->feature, $name, $property->type, $property->value, $profile);
                        $updateAction($entity->feature, $name, $entity->commands);
                        break;
                }
            }
        }
    }

    private function CreateProfile($name, $type, $associations)
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
            foreach ($associations as $association) {
                IPS_SetVariableProfileAssociation($name, $association, enumToName($association), '', -1);
            }
        }
        return $name;
    }
	
    /**
     * Startet die Zirkulationspumpe für die angegebene Dauer in Minuten.
     * Berechnet automatisch das Zeitfenster und räumt nach Ablauf auf.
     * Nach dem Stopp bleibt eine 30-Minuten-Sperre aktiv (Abkühlschutz).
     */
    public function StartZirkulation(int $minutes)
    {
        // Sperre prüfen — Timestamp-basiert für Robustheit bei IPS-Neustart
        $sperreEnde = $this->ReadAttributeInteger('ZirkulationSperreEnde');
        if ($sperreEnde > 0 && time() < $sperreEnde) {
            $rest = $sperreEnde - time();
            $this->SendDebug('Zirkulation', 'Gesperrt, Anfrage ignoriert (noch ' . $rest . 's)', 0);
            return;
        }
        // Falls Sperre abgelaufen aber Variable noch true → aufräumen
        if ($sperreEnde > 0 && time() >= $sperreEnde) {
            $this->ZirkulationSperreAufheben();
        }

        // Zeitfenster berechnen (Viessmann erfordert 10-Minuten-Intervalle)
        $now = time();
        $startMinutes = intval(date('i', $now));
        $startRounded = $startMinutes - ($startMinutes % 10); // abrunden auf 10er
        $startTime = date('H', $now) . ':' . sprintf('%02d', $startRounded);

        $endTimestamp = $now + ($minutes * 60);
        $endMinutes = intval(date('i', $endTimestamp));
        $endRounded = $endMinutes + (10 - $endMinutes % 10); // aufrunden auf 10er
        $endHour = intval(date('H', $endTimestamp));
        if ($endRounded >= 60) {
            $endRounded = 0;
            $endHour++;
        }
        if ($endHour >= 24) {
            $endTime = '24:00';
        } else {
            $endTime = sprintf('%02d:%02d', $endHour, $endRounded);
        }

        // Sonderfälle Tagesgrenze
        if ($startTime >= '23:50' && $endTime < $startTime) {
            $startTime = '23:50';
            $endTime = '24:00';
        }

        $this->SendDebug('Zirkulation', 'Start: ' . $startTime . ', Ende: ' . $endTime . ', Dauer: ' . $minutes . ' Min.', 0);

        // Schedule setzen
        $this->SetCirculationSchedule($startTime, $endTime);

        // Sperre setzen und Zähler erhöhen
        $this->SetValue('ZirkulationAktiv', true);
        $counter = $this->GetValue('ZirkulationAnzahl');
        $this->SetValue('ZirkulationAnzahl', $counter + 1);

        // Timer zum Schedule-Aufräumen (Dauer + 1 Minute Puffer)
        $this->SetTimerInterval('ZirkulationStop', ($minutes + 1) * 60 * 1000);

        // Sperre: Timestamp speichern + Timer als Backup
        $this->WriteAttributeInteger('ZirkulationSperreEnde', time() + 30 * 60);
        $this->SetTimerInterval('ZirkulationSperre', 30 * 60 * 1000);
    }

    /**
     * Stoppt die Zirkulationspumpe: löscht den Zeitplan.
     * Die Sperre bleibt bis zum Ablauf der 30 Minuten bestehen.
     * Wird automatisch vom Timer aufgerufen oder kann manuell genutzt werden.
     */
    public function StopZirkulation()
    {
        $this->SendDebug('Zirkulation', 'Zeitplan wird gelöscht', 0);

        // Schedule leeren
        $this->SetCirculationSchedule('', '');

        // Stop-Timer deaktivieren
        $this->SetTimerInterval('ZirkulationStop', 0);

        // Sperre bleibt aktiv bis ZirkulationSperre-Timer abläuft
    }

    /**
     * Hebt die Zirkulationssperre auf. Wird automatisch nach 30 Minuten aufgerufen.
     */
    public function ZirkulationSperreAufheben()
    {
        $this->SendDebug('Zirkulation', 'Sperre aufgehoben', 0);

        $this->SetValue('ZirkulationAktiv', false);
        $this->WriteAttributeInteger('ZirkulationSperreEnde', 0);
        $this->SetTimerInterval('ZirkulationSperre', 0);
    }

    /**
     * Setzt oder löscht den Zirkulationspumpen-Zeitplan.
     * Leere Strings für Start/End löschen den Schedule.
     */
    private function SetCirculationSchedule(string $start, string $end)
    {
        $action = 'heating.dhw.pumps.circulation.schedule/commands/setSchedule';
        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        $daySchedule = [];
        foreach ($days as $day) {
            if ($start !== '' && $end !== '') {
                $daySchedule[$day] = [[
                    'mode'     => 'on',
                    'start'    => $start,
                    'end'      => $end,
                    'position' => 0
                ]];
            } else {
                $daySchedule[$day] = [];
            }
        }

        $this->RequestDeviceData($action, ['newSchedule' => $daySchedule]);
    }

    /**
     * @deprecated Nutze StartZirkulation() und StopZirkulation() stattdessen.
     * Bleibt für Abwärtskompatibilität erhalten.
     */
    public function CreateZirku(string $start, string $end, bool $activate)
    {
        if ($activate) {
            $this->SetCirculationSchedule($start, $end);
        } else {
            $this->SetCirculationSchedule('', '');
        }
    }
}
