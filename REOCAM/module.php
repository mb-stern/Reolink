<?php
declare(strict_types=1);

class Reolink extends IPSModuleStrict
{
    public function Create(): void
    {
        parent::Create();

        // Basis
        $this->RegisterPropertyString("CameraIP", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("StreamType", "sub");
        $this->RegisterPropertyBoolean("InstanceStatus", true);

        // Sichtbarkeit / UI
        $this->RegisterPropertyBoolean("ShowMoveVariables", true);
        $this->RegisterPropertyBoolean("ShowSnapshots", true);
        $this->RegisterPropertyBoolean("ShowArchives", true);
        $this->RegisterPropertyBoolean("ShowTestElements", false);
        $this->RegisterPropertyBoolean("ShowVisitorElements", false);

        // Polling
        $this->RegisterPropertyBoolean("EnablePolling", false);
        $this->RegisterPropertyInteger("PollingInterval", 2);

        // API-Feature-Schalter
        $this->RegisterPropertyBoolean("EnableApiWhiteLed", true);
        $this->RegisterPropertyBoolean("EnableApiEmail", true);
        $this->RegisterPropertyBoolean("EnableApiPTZ", false);
        $this->RegisterPropertyBoolean("EnableApiFTP", true);
        $this->RegisterPropertyBoolean('EnableApiSensitivity', true); 
        $this->RegisterPropertyBoolean('EnableApiSiren', true); 
        $this->RegisterPropertyBoolean('EnableApiRecord', true);
        $this->RegisterPropertyBoolean("EnableApiIR", true);
        $this->RegisterPropertyBoolean('EnableFirmwareVariables', true);
        $this->RegisterPropertyBoolean("UseHttps", false);

        // Archiv
        $this->RegisterPropertyInteger("MaxArchiveImages", 20);

        // Attribute
        $this->RegisterAttributeBoolean("TokenRefreshing", false);
        $this->RegisterAttributeInteger("ApiTokenExpiresAt", 0);
        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString("ApiToken", "");
        $this->RegisterAttributeString("PtzStyle", "");
        $this->RegisterAttributeString("PtzPresetsCache", "");
        $this->RegisterAttributeString("AbilityCache", "");
        $this->RegisterAttributeInteger("ExecLastTs", 0);
        $this->RegisterAttributeString('ApiVersionCache', '{}');
        $this->RegisterAttributeString('ApiCache', '{}');
        $this->RegisterAttributeString('DevInfoCache', '');
        $this->RegisterAttributeString('ModelImageCache', '{}');
        $this->RegisterAttributeInteger('DevInfoLastRefresh', 0);
        $this->RegisterAttributeInteger('FirmwareLastCheckTs', 0);
        $this->RegisterAttributeInteger('LastTokenErrorTs', 0);

        // Hook-Adresse (ohne /hook/)
        $address = 'reolink_' . $this->InstanceID;   
        $this->RegisterHook($address);
        $this->WriteAttributeString('CurrentHook', '/hook/' . $address);

        // Timer
        $this->RegisterTimer("Person_Reset",   0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Person");');
        $this->RegisterTimer("Tier_Reset",     0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Tier");');
        $this->RegisterTimer("Fahrzeug_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Fahrzeug");');
        $this->RegisterTimer("Bewegung_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Bewegung");');
        $this->RegisterTimer("Test_Reset",     0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Test");');
        $this->RegisterTimer("Besucher_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Besucher");');
        $this->RegisterTimer("PollingTimer",      0, 'REOCAM_Polling($_IPS[\'TARGET\']);');
        $this->RegisterTimer("ApiRequestTimer",   0, 'REOCAM_ExecuteApiRequests($_IPS[\'TARGET\'], false);');
        $this->RegisterTimer("TokenRenewalTimer", 0, 'REOCAM_GetToken($_IPS[\'TARGET\']);');
        $this->RegisterTimer("FirmwareCheckTimer", 0, 'REOCAM_FirmwareCheckTimer($_IPS[\'TARGET\']);');

    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $enabled = $this->ReadPropertyBoolean("InstanceStatus");
        if (!$enabled) {
            $this->SetStatus(104);
            foreach ([
                "Person_Reset","Tier_Reset","Fahrzeug_Reset","Bewegung_Reset",
                "Test_Reset","Besucher_Reset","PollingTimer","ApiRequestTimer","TokenRenewalTimer","FirmwareCheckTimer"
            ] as $t) {
                $this->SetTimerInterval($t, 0);
            }
            $this->WriteAttributeString("ApiToken", "");
            $this->WriteAttributeInteger("ApiTokenExpiresAt", 0);
            $this->WriteAttributeBoolean("TokenRefreshing", false);
            return;
        }

        $this->SetStatus(102);

        $hookPath = $this->ReadAttributeString("CurrentHook");

        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");
        if ($this->ReadPropertyBoolean("ShowMoveVariables")) { $this->CreateMoveVariables(); } else { $this->RemoveMoveVariables(); }
        if (!$this->ReadPropertyBoolean("ShowSnapshots")) { $this->RemoveSnapshots(); }
        if ($this->ReadPropertyBoolean("ShowArchives")) { $this->CreateOrUpdateArchives(); } else { $this->RemoveArchives(); }
        if ($this->ReadPropertyBoolean("ShowTestElements")) { $this->CreateTestElements(); } else { $this->RemoveTestElements(); }
        if ($this->ReadPropertyBoolean("ShowVisitorElements")) { $this->CreateVisitorElements(); } else { $this->RemoveVisitorElements(); }

        if ($this->ReadPropertyBoolean("EnablePolling")) {
            $interval = max(1, (int)$this->ReadPropertyInteger("PollingInterval"));
            $this->SetTimerInterval("PollingTimer", $interval * 1000);
        } else {
            $this->SetTimerInterval("PollingTimer", 0);
        }

        // API-Schalter
        $enableWhiteLed   = $this->ReadPropertyBoolean("EnableApiWhiteLed");
        $enableIR         = $this->ReadPropertyBoolean("EnableApiIR");      
        $enableEmail      = $this->ReadPropertyBoolean("EnableApiEmail");
        $enablePTZ        = $this->ReadPropertyBoolean("EnableApiPTZ");
        $enableFTP        = $this->ReadPropertyBoolean("EnableApiFTP");
        $enableSensitivity= $this->ReadPropertyBoolean("EnableApiSensitivity");
        $enableSiren      = $this->ReadPropertyBoolean("EnableApiSiren");
        $enableRecord     = $this->ReadPropertyBoolean("EnableApiRecord");

        $anyFeatureOn = (
            $enableWhiteLed || $enableIR || $enableEmail || $enablePTZ ||
            $enableFTP || $enableSensitivity || $enableSiren || $enableRecord
        );


        $this->CreateOrUpdateApiVariablesUnified();

        // Firmware-Check-Timer: einmal pro Tag
        if ($this->ReadPropertyBoolean('EnableFirmwareVariables')) {
            // Timer für spätere automatische Checks
            $this->SetTimerInterval('FirmwareCheckTimer', 24 * 60 * 60 * 1000);

            $this->FirmwareCheckTimer();
        } else {
            $this->SetTimerInterval('FirmwareCheckTimer', 0);
        }

        $this->SetTimerInterval("ApiRequestTimer", 10 * 1000); // läuft immer für Online-Check
        if ($anyFeatureOn) {
            $this->GetToken();
            $this->ExecuteApiRequests(true);
        } else {
            $this->SetTimerInterval("TokenRenewalTimer", 0);
        }

        $this->UpdateOnlineStatus();

    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case "WhiteLed":
                $ok = $this->SetWhiteLed((bool)$Value);
                if ($ok) { $this->SetValue($Ident, (bool)$Value); }
                else     { $this->UpdateWhiteLedStatus(); }
                break;

            case "Mode":
                $ok = $this->SetMode((int)$Value);
                if ($ok) { $this->SetValue($Ident, (int)$Value); }
                else     { $this->UpdateWhiteLedStatus(); }
                break;

            case "Bright":
                $ok = $this->SetBrightness((int)$Value);
                if ($ok) { $this->SetValue($Ident, (int)$Value); }
                else     { $this->UpdateWhiteLedStatus(); }
                break;

            case "EmailNotify":
                $ok = $this->EmailApply((bool)$Value, null, null);
                if ($ok) { $this->SetValue($Ident, (bool)$Value); }
                else     { $this->EmailApply(null, null, null); }
                break;

            case "EmailInterval":
                $ok = $this->EmailApply(null, (int)$Value, null);
                if ($ok) { $this->SetValue($Ident, (int)$Value); }
                else     { $this->EmailApply(null, null, null); }
                break;

            case "EmailContent":
                $ok = $this->EmailApply(null, null, (int)$Value);
                if ($ok) { $this->SetValue($Ident, (int)$Value); }
                else     { $this->EmailApply(null, null, null); }
                break;

            case "FTPEnabled":
                $ok = $this->FtpApply((bool)$Value);
                if ($ok) { $this->SetValue($Ident, (bool)$Value); }
                else     { $this->UpdateFtpStatus(); }
                break;

            case "IRLights":
                $ok = $this->IR_SetModeInt((int)$Value);
                if ($ok) { $this->SetValue($Ident, (int)$Value); }
                else     { $this->UpdateIrStatus(); }
                break;

            case "MdSensitivity":
                $lvl = max(1, min(50, (int)$Value));
                $ok = $this->SetMdSensitivity($lvl);
                if ($ok) { $this->SetValue($Ident, $lvl); }
                break;

            case "SirenEnabled":
                $ok = $this->SetSirenEnabled((bool)$Value);
                if ($ok) { $this->SetValue($Ident, (bool)$Value); }
                break;

            case "SirenAction":
                $val = (int)$Value;
                $ok = false;
                if ($val === 0) $ok = $this->SirenManualSwitch(false);
                elseif ($val === 100) $ok = $this->SirenManualSwitch(true);
                elseif ($val >= 1 && $val <= 5) $ok = $this->SirenPlayTimes($val);

                if ($ok) { $this->SetValue('SirenAction', 0); }
                break;

            case "RecEnabled":
                $ok = $this->SetRecEnabled((bool)$Value);
                if ($ok) { $this->SetValue($Ident, (bool)$Value); }
                else     { $this->UpdateRecStatus(); }
                break;

            case "ResetApiCache":
                $this->ResetApiCache();
                break;

            default:
                throw new Exception("Invalid Ident");
        }
    }

    public function SetInstanceStatus(bool $value): bool
    {
        IPS_SetProperty($this->InstanceID, 'InstanceStatus', $value);

        $result = IPS_ApplyChanges($this->InstanceID);

        return $result;
    }


    private function isActive(): bool
    {
        return $this->ReadPropertyBoolean("InstanceStatus") && ($this->GetStatus() === 102);
    }

    private function dbg(string $topic, string $message, $data = null, bool $ignored = false): void
    {
        $label = strtoupper($topic);
        $text  = $message;
        if ($data !== null) {
            $text .= ' | ' . $this->toStr($this->redactDeep($data));
        }
        $this->SendDebug($label, $text, 0);
    }

    private function toStr($v): string
    {
        if (is_string($v)) return $v;
        return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function redactDeep($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                $lk = strtolower((string)$k);

                if (in_array($lk, ['password','pass','pwd','token','apikey','authorization','auth','bearer','secret'], true)) {
                    $out[$k] = '***';

                } elseif (in_array($lk, ['user','username'], true)) {
                    // Nur skalare Werte maskieren; bei Arrays rekursiv weitergehen
                    if (is_scalar($v) || (is_object($v) && method_exists($v, '__toString'))) {
                        $out[$k] = $this->maskMiddle((string)$v);
                    } else {
                        $out[$k] = $this->redactDeep($v);
                    }

                } else {
                    $out[$k] = $this->redactDeep($v);
                }
            }
            return $out;
        }

        if (is_string($value)) {
            $s = $value;
            $s = preg_replace('/([?&])(user|username)=([^&#\s]*)/i', '$1$2=***', $s);
            $s = preg_replace('/([?&])(password|pass|pwd)=([^&#\s]*)/i', '$1$2=***', $s);
            $s = preg_replace('/([?&])(token|apikey)=([^&#\s]*)/i', '$1$2=***', $s);
            $s = preg_replace('/(Authorization:\s*Bearer\s+)[^\s"]+/i', '$1***', $s);
            $s = preg_replace('/(Authorization:\s*Basic\s+)[A-Za-z0-9+\/=]+/i', '$1***', $s);
            return $s;
        }

        return $value;
    }

    private function maskMiddle(string $s): string
    {
        if ($s === '') return '';
        if (mb_strlen($s) <= 2) return str_repeat('*', mb_strlen($s));
        return mb_substr($s, 0, 1) . '***' . mb_substr($s, -1);
    }

    // ---------------------------
    // Webhook + Formular
    // ---------------------------

   public function GetConfigurationForm(): string
    {
        // Webhook ermitteln/registrieren
        $hookPath = $this->ReadAttributeString('CurrentHook');
        $webhookFull = $this->BuildWebhookFullUrl($hookPath);

        // DevInfo für das Formular IMMER frisch von der Kamera holen
        $dev = $this->apiGetDevInfoFresh();

        // Fallback, falls die Abfrage schiefgeht
        if (!is_array($dev)) {
            $dev = [];
        }

        // Build-String etwas hübscher machen (ohne "build ")
        $build = $dev['buildDay'] ?? '';
        if (is_string($build) && stripos($build, 'build ') === 0) {
            $build = trim(substr($build, 6)); // "build 2412021483" -> "2412021483"
        }
        if ($build === '') {
            $build = 'n/a';
        }

        // Zeilenweise Ausgabe vorbereiten
        $lines = [
            'Gerät: '     . ($dev['model']   ?? 'unbekannt'),
            'Firmware: '  . ($dev['firmVer'] ?? 'n/a'),
            'HW: '        . ($dev['hardVer'] ?? 'n/a'),
            'Build: '     . $build,
            'Seriennr.: ' . ($dev['serial']  ?? 'n/a'),
            'Detail: '    . ($dev['detail']    ?? 'n/a'),
        ];

        // Bild holen (Base64, bereits verkleinert)
        $imageData = $this->getModelImageBase64($dev);

        // Firmwarecheck-Text vorbereiten
        $fwInfo = $this->FirmwareCheck($dev);
        $firmwareCheckMessage = $this->FirmwareCheckMessage($dev, $fwInfo);


        // Header-Element zusammenbauen: Bild links, Infos rechts (zeilenweise)
        if (!empty($imageData)) {
            $infoColumn = [
                'type'  => 'ColumnLayout',
                'items' => [
                    ['type' => 'Label', 'name' => 'DevLine1', 'caption' => $lines[0]],
                    ['type' => 'Label', 'name' => 'DevLine2', 'caption' => $lines[1]],
                    ['type' => 'Label', 'name' => 'DevLine3', 'caption' => $lines[2]],
                    ['type' => 'Label', 'name' => 'DevLine4', 'caption' => $lines[3]],
                    ['type' => 'Label', 'name' => 'DevLine5', 'caption' => $lines[4]],
                    ['type' => 'Label', 'name' => 'DevLine6', 'caption' => $lines[5]],
                ],
            ];

            $deviceHeaderElement = [
                'type'  => 'RowLayout',
                'items' => [
                    [
                        'type'  => 'Image',
                        'name'  => 'DeviceImage',
                        'image' => $imageData
                        // width/height optional, Bild ist physisch verkleinert
                    ],
                    $infoColumn,
                ],
            ];
        } else {
            // Fallback ohne Bild: nur Infos untereinander
            $deviceHeaderElement = [
                'type'  => 'ColumnLayout',
                'items' => [
                    ['type' => 'Label', 'name' => 'DevLine1', 'caption' => $lines[0]],
                    ['type' => 'Label', 'name' => 'DevLine2', 'caption' => $lines[1]],
                    ['type' => 'Label', 'name' => 'DevLine3', 'caption' => $lines[2]],
                    ['type' => 'Label', 'name' => 'DevLine4', 'caption' => $lines[3]],
                    ['type' => 'Label', 'name' => 'DevLine5', 'caption' => $lines[4]],
                    ['type' => 'Label', 'name' => 'DevLine6', 'caption' => $lines[5]],
                ],
            ];
        }

        $form = [
            'elements' => [
                [
                    'type'    => 'Label',
                    'caption' => ''
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'WebhookFull',
                    'caption' => 'Webhook für Kamerakonfiguration: ' . $webhookFull
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'InstanceStatus',
                    'caption' => 'Instanz aktivieren'
                ],
                [
                    'type'    => 'CheckBox',
                    'name'    => 'UseHttps',
                    'caption' => 'HTTPS verwenden'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'CameraIP',
                    'caption' => 'Kamera IP'
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'Username',
                    'caption' => 'Benutzername'
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'Password',
                    'caption' => 'Passwort'
                ],
                [
                    'type'    => 'Select',
                    'name'    => 'StreamType',
                    'caption' => 'Stream-Typ',
                    'options' => [
                        ['caption' => 'Mainstream', 'value' => 'main'],
                        ['caption' => 'Substream',  'value' => 'sub'],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'API-Funktionen',
                    'items'   => [
                        ['type' => 'CheckBox', 'name' => 'EnableApiWhiteLed',       'caption' => 'LED-Scheinwerfer'],
                        ['type' => 'CheckBox', 'name' => 'EnableApiIR',             'caption' => 'IR-Beleuchtung'],
                        ['type' => 'CheckBox', 'name' => 'EnableApiEmail',          'caption' => 'E-Mail Alarm'],
                        ['type' => 'CheckBox', 'name' => 'EnableApiFTP',            'caption' => 'FTP'],
                        ['type' => 'CheckBox', 'name' => 'EnableApiSensitivity',    'caption' => 'Sensitivität'],
                        ['type' => 'CheckBox', 'name' => 'EnableApiSiren',          'caption' => 'Sirene'],
                        ['type' => 'CheckBox', 'name' => 'EnableApiRecord',         'caption' => 'Kameraaufzeichnung'],
                        ['type' => 'CheckBox', 'name' => 'EnableApiPTZ',            'caption' => 'PTZ / Presets / Zoom'],
                        ['type' => 'CheckBox', 'name' => 'EnableFirmwareVariables', 'caption' => 'Firmware-Variablen'],
                        [
                            'type'    => 'Button',
                            'caption' => 'API-Version Cache zurücksetzen',
                            'onClick' => "IPS_RequestAction(\$id, 'ResetApiCache', true); echo 'Cache gelöscht.';"
                        ],
                    ],
                ],
                [
                    'type'    => 'ExpansionPanel',
                    'caption' => 'Bewegungserkennung/Aufnahmen',
                    'items'   => [
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'EnablePolling',
                            'caption' => 'Polling aktivieren (für Kameras ohne Webhook-Unterstützung)'
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => 'PollingInterval',
                            'caption' => 'Polling-Intervall',
                            'suffix'  => 'Sekunden',
                            'minimum' => 2,
                            'maximum' => 3600
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowTestElements',
                            'caption' => 'Test-Funktion Bewegungserkennung (Aktivierbar im Kamerainterface/Webhook)'
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowVisitorElements',
                            'caption' => 'Besucher-Erkennung aktivieren (für Doorbell)'
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowMoveVariables',
                            'caption' => 'Intelligente Bewegungserkennung'
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowSnapshots',
                            'caption' => 'Schnappschüsse anzeigen'
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'ShowArchives',
                            'caption' => 'Bildarchive anzeigen'
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => 'MaxArchiveImages',
                            'caption' => 'Maximale Anzahl Archivbilder',
                            'minimum' => 1,
                            'suffix'  => 'Bilder'
                        ],
                    ],
                ],
            ],

                'actions' => [
                [
                    'type'    => 'Label',
                    'name'    => 'FirmwareCheck',
                    'caption' => $firmwareCheckMessage
                ],
                $deviceHeaderElement,
                [
                    'type'    => 'Label',
                    'caption' => ''
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                                'type'   => 'Image',
                                'onClick'=> "echo 'https://paypal.me/mbstern';",
                                'image'=> "data:image/jpeg;base64,/9j/4QAYRXhpZgAASUkqAAgAAAAAAAAAAAAAAP/sABFEdWNreQABAAQAAAA8AAD/7gAOQWRvYmUAZMAAAAAB/9sAhAAGBAQEBQQGBQUGCQYFBgkLCAYGCAsMCgoLCgoMEAwMDAwMDBAMDg8QDw4MExMUFBMTHBsbGxwfHx8fHx8fHx8fAQcHBw0MDRgQEBgaFREVGh8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx8fHx//wAARCABLAGQDAREAAhEBAxEB/8QAqwABAAICAwEBAAAAAAAAAAAAAAUGAgcDBAgJAQEBAAIDAQAAAAAAAAAAAAAAAAMEAgUGARAAAQMCAwMEDwMICwAAAAAAAgEDBAAFERIGIRMHMdEUFkFRcSKyk6PDJFSEFTZGZmEyCIGxQlKSIzODkaFigmOz00QlVRgRAAICAQIDBQYFBQAAAAAAAAABAgMREgQhMQVBUWEiE/BxgaGxBpHRQhQVwfEyUiP/2gAMAwEAAhEDEQA/AN+WWywr/CS63VDfkPmeUc5CICJKKCKCqbNlAd/qNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89AOo2mvVi8YfPQDqNpr1YvGHz0A6jaa9WLxh89ARnuVr3/wC4t+97o3PSui51+9jly5vvZezhQEnob4ajd1zw1oCeoBQCgFAeZtWfik1ZbtT3W3W22284MKU7GYceR4nCFk1DMSi4KbVHHYldDT0eEoJtvLRrrN7JSaSIr/1nr3/q7Z+y/wD6tS/wtXfL5GH76Xci4aC/FPFul1j2zVFtC3dKMWmrhGMiZEyXAd6B98Iqv6WZcOzVTc9HcYuUHnHYTVb1N4Zv6tIXhQCgFAV/569g85QGWhvhqN3XPDWgJ6gFAKA4LhLbhwJMxxcG4zRvGq9psVJfzVlGOWkeN4WT53SZJyZD0lxcTfMnTVe2aqS/nru0sLBz74s6XSj7SVD6rJfTR+g+6ZIAjiRKgiiY44rsSitZ44JcT6E6Nv8ADvunok2Kpd6KNPgf3wdbREISw/prkd3t5U2OMjZbHeQ3FanHkTdVi2KAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKAp/F+6LbOGOpZaLlLoLrIL/afTcp/W5VrYw1XRXiRXvEGeElElHKAqRLsERTFVVewiJXZS5GjTXNmAWi7GSCEJ9SXYibo+aq2h9xk9zUuco/ii26T0VKalt3C6AjaMrmYjLgpKachHhyYdqrNVLzlmj6l1aMouuvjnm/yPWPBCG8zpJ19xFQZUozax7IiIhin94VrnOuTTuS7om5+2q3Hbtv9UvyRsKtMdEKAUBX/AJ69g85QGWhvhqN3XPDWgJ6gFAKA1F+KK59E4XnGQsCuE2Oxh2xFVeX/ACq2nSIZuz3JlTeSxA8waGY3l9RzDYy0Z4/auAp4VdZHmct1aeKH4tI2xpzTl11Fcfd9uESfQCdJXCyigjgiqq7eyqVjudzCmOqXI5/Z7Ke4nohz5l8snAu6HIA7zMaZjIuJtRlI3CTtZiQRHu7a1F/XYJeRNvxOg232xNyzbJKPhzNwwYMWBDZhxG0ajRwRtpseRBHYlc3ZNzk5Pi2djVXGuKjFYijnrAzFAKAr/wA9ewecoDLQ3w1G7rnhrQE9QCgFAUzidwvtnEC3QoNwmyITcJ5XwWPkXMRAod8hiXIi7Kt7TduhtpJ5IbqVNYZp7UfBCFodyO7ZnZ10dnIYPKbYkLYtqKphuhTaSr2e1XRdO6h6revTHByv3BtmowjBOXF9hduB1knx7hc50qM6wKNAw0roEGZSJSLDMicmVKq9cvjKMYpp8cnv2ztpxnOUk1wxx9vA29XOHXigFAKAUBX/AJ69g85QGWhvhqN3XPDWgNAyeKvFSdB1ZqS36lhQbTY5xsQ7e+wwrj4K4qADSqKqSoOXl5a6JbOhOEHFuUlz4mud02m0+CNl2HjvpKPpawytX3Fm3Xy5xQffiNg4eVCVUF0hBD3YuCmdM3YWtfZ06bnJVrMUyxHcR0rVzJ5njHw3eisTG7yBRJMz3czI3TyNlJyiWTMoYJ3pouK7KgexuTxp44z8CRXw7yQvOvdM2y7rYXZo+/SiuS24IiZkjbYEeYyEVEEwBfvKlY1bWc0pY8ucGN16hFvtSbNadfNfsabjaiO7xXAefVkbcTTe8JBVcSwFEXL3tdB+w27tdWh8Fzyzj/5TdxpVznHjLGnCybGd4kaSiOtxbhPCPOyCUhlEM0aNRRVAiEVRFTkwrSrpt0lmMcx+p0b6xt4NRnLEscefDwIy6a2emah0tGsEpCgXQ3XJJ7vabTRYKnfpmH7h7anq2SjXY7F5o4x737IrX9Sc7qY0vyTznh2L3+5lh1pqVrTGlLpf3W98NuYJ4WVLLnNNgBmwXDMSonJWv29XqTUe83Vk9MWzWjf4jrYPDTrZJgC3dHJbkGNZhexzutoJqSuKCKgI2aES5fs7NbB9Kl62hPy4zkr/ALtaNXaWuBxb04xpOy3vVD7Vll3ljpLFuQjkO5FxUVEQDeEmXBVXLhVaWym5yjDzKPaSq9KKcuGS02DUNk1Da2rrZZjc63vYo2+3jhiK4EioqIqKi8qKlVrKpQlpksMkjJSWUdD569g85UZkcGmSlDolSiBvZQtSFjtoqIpOIpZBxXBExKsoYys8jx8jWHCf8PVhTTrczXdl3uoCkOuE068RCLeKICELR7tccFL8tbje9TlrxVLy4KdO1WPMuJxM6R4h6Y1/q2XbNJRb/Evyf8ZOdeZaajMoK5WVA9uVBwBQRExypguFeu+qyqCc3Fx5rvGicZPCzkgLzojqx+G9+FqdBtt8W5dOhMKQkayVcRsGx3akmJMivIuxO5U1e49Td5hxjpx8P7kcq9NWHweS5aI4d6kj6KvmpLuBzteapj/vd4oi40w5gIspjlQVyd8SdwexUM93X68IrhVBkW5oslt54WbJL6lt0hwv0/CtsCVcbeJXoAE3ycMjQXeX7mZW1y9yot51SyUpKMvJ/T6kHT+iUwhGU4/9O33/AEKzE01re3WO+WIbA1MdnOOGt2J1vExPBO9QlzKX6Q4qmC1fnuaJ2Qs1uOn9OGauGz3VdVlXpqTlnzZXt7iW01o++QdR2WTIiKMS0Wnd5s4LjKczEYIiLjji6u3kqtut5XKqaT805/L2Rc2XT7YX1uS8sK/D/J5z9SF11B4q604XJa5tjbg3i43NtqVEYdBRagNkh70yJxUVVIU2Cv5Kh28qKrtSlmKj8zdWKc4YxxyQnEfgA63EusvS7DlxuF7ksNNxl3bbUCNsKQYKRJmU1aBFXlw2VNtepZaU+CivxfYYW7b/AF7Tk1fw51fbeIQXq2QblcbMlsj26CdlnNQpUbo4CCtkryLi2WVS2duvKN1XKrS3FS1NvUspns6ZKWVnGOw2bwp0m3pjR0eAkJ23OvOuypEJ+QMtxs3S5CeAQElyiOOCcta7eXepZnOfhgsUw0xwd/569g85VUlMtDfDUb7Ccx/bWgJ6gFAdO42a0XJWVuMJiYsY95H6Q0Du7P8AWDOi5V+1KzjZKPJ4PHFPmdysD0UAoBQCgFAKAUBX8U69YY7egcn8ygIeLj0iZuen/wAc83unDo2P879L9bLsoDs+k/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAek/UHkKAiv3fvf/db/P8A4nvT+H4nd0B//9k="
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => "Sag danke und unterstütze den Modulentwickler: paypal.me/mbstern"
                        ],
                    ],
                ],
            ],
        ];

        return json_encode($form);
    }

    // ---------------------------
    // Firmware und Gerätecheck
    // ---------------------------

    // 1) Reiner Check: holt README, parst, vergleicht -> gibt Info-Array zurück (oder null bei Fehler)
    private function FirmwareCheck(array $dev): ?array
    {
        $firm = trim((string)($dev['firmVer'] ?? ''));
        if ($firm === '') {
            return null;
        }

        $readme = $this->fetchFirmwareReadme();
        if ($readme === null || $readme === '') {
            return null;
        }

        return $this->findLatestFirmwareForInstalled($readme, $firm);
    }

    // 2) Reine Darstellung fürs Formular (kein SetValue, keine Seiteneffekte)
    private function FirmwareCheckMessage(array $dev, ?array $info): string
    {
        $firm = trim((string)($dev['firmVer'] ?? ''));
        $buildDay = trim((string)($dev['buildDay'] ?? ''));
        if (stripos($buildDay, 'build ') === 0) {
            $buildDay = trim(substr($buildDay, 6));
        }

        if ($firm === '') {
            return 'ℹ️ Firmware: unbekannt – Online-Firmwareprüfung nicht möglich (keine Firmwareangabe).';
        }

        $base = 'ℹ️ Firmware: ' . $firm;
        if ($buildDay !== '') {
            $base .= ' (Build ' . $buildDay . ')';
        }

        if ($info === null || !is_array($info) || !array_key_exists('installed_found', $info)) {
            return $base . ' – Online-Firmwareprüfung nicht möglich.';
        }

        if (!$info['installed_found']) {
            return $base . ' – Firmware im README nicht gefunden.';
        }

        if (empty($info['is_newer'])) {
            return 'Es wurde keine neuere Firmware gefunden.';
        }

        $latest = (string)($info['latest_version'] ?? '');
        $url    = (string)($info['download_url'] ?? '');

        if ($latest !== '' && $url !== '') {
            return 'Neue Firmware gefunden: ' . $latest . ' – Download: ' . $url;
        }

        return 'Neue Firmware gefunden.';
    }

    private function fetchFirmwareReadme(): ?string
    {
        // ggf. anpassen, wenn du eine eigene Kopie nutzt
        $url = 'https://raw.githubusercontent.com/AT0myks/reolink-fw-archive/main/README.md';

        $opts = [
            'http' => [
                'method'        => 'GET',
                'timeout'       => 10,
                'ignore_errors' => true
            ],
            // falls es ein TLS-Problem gibt, testweise Peer-Check aus:
            'ssl'  => [
                'verify_peer'      => false,
                'verify_peer_name' => false
            ]
        ];

        $this->SendDebug('FirmwareCheck', 'Lade Firmware-README von ' . $url, 0);
        $result = @Sys_GetURLContent($url);

        if ($result === false || $result === '') {
            $err = error_get_last();
            $this->SendDebug(
                'FirmwareCheck',
                'Fehler beim Laden des Firmware-README: ' . ($err['message'] ?? 'unbekannt'),
                0
            );
            return null;
        }

        return $result;
    }

    private function findLatestFirmwareForInstalled(string $readme, string $firmVer): ?array
    {
        $firmVer = trim($firmVer);
        if ($firmVer === '') {
            return null;
        }

        // Wir suchen mit führendem "v", weil die README-Tabellen so aussehen:
        // [v3.0.0.3471_2406116464](...)
        $searchVer = $firmVer;
        if ($searchVer[0] !== 'v') {
            $searchVer = 'v' . $searchVer;
        }

        // 1) Position der installierten Firmware im gesamten README finden
        $posInstalled = strpos($readme, $searchVer);
        if ($posInstalled === false) {
            // Firmware kommt nirgendwo im README vor
            return [
                'installed_found'   => false,
                'installed_version' => $searchVer,
                'latest_version'    => null,
                'download_url'      => null,
                'is_newer'          => false
            ];
        }

        // 2) Start des passenden Abschnitts (### IPC_...) nach oben suchen
        $before       = substr($readme, 0, $posInstalled);
        $sectionStart = strrpos($before, "\n  ### ");
        if ($sectionStart === false) {
            // Fallback: kein Abschnitts-Header gefunden → gesamte Datei verwenden (sollte nicht vorkommen)
            $sectionText = $readme;
        } else {
            $sectionText = substr($readme, $sectionStart);

            // Bis zum nächsten Abschnitt (nächste ###) begrenzen
            $nextHeadingPos = strpos($sectionText, "\n  ### ", 6);
            if ($nextHeadingPos !== false) {
                $sectionText = substr($sectionText, 0, $nextHeadingPos);
            }
        }

        // 3) Nur in diesem Abschnitt die Firmware-Tabelle parsen
        $lines   = preg_split("/\r\n|\r|\n/", $sectionText);
        $entries = [];

        foreach ($lines as $line) {
            // Zeilen wie:
            // [v3.0.0.3471_2406116464](https://...) | 2024-06-11 | ...
            if (preg_match('/\[(v[^\]]+)\]\(([^)]+)\)\s*\|/u', $line, $m)) {
                $entries[] = [
                    'version' => $m[1],
                    'url'     => $m[2]
                ];
            }
        }

        if ($entries === []) {
            // Im Abschnitt keine Firmware-Zeilen → besser ein "not found" zurückgeben
            return [
                'installed_found'   => false,
                'installed_version' => $searchVer,
                'latest_version'    => null,
                'download_url'      => null,
                'is_newer'          => false
            ];
        }

        // 4) In dieser Tabelle die installierte Version und die neueste Version bestimmen
        $installedFound = false;
        $latest         = null;

        foreach ($entries as $entry) {
            if ($entry['version'] === $searchVer) {
                $installedFound = true;
            }

            if ($latest === null || $this->compareFirmwareStrings($entry['version'], $latest['version']) > 0) {
                $latest = $entry;
            }
        }

        // Falls aus irgendeinem Grund kein "latest" gesetzt ist, lieber ein defensives Ergebnis zurückgeben
        if ($latest === null) {
            return [
                'installed_found'   => $installedFound,
                'installed_version' => $searchVer,
                'latest_version'    => null,
                'download_url'      => null,
                'is_newer'          => false
            ];
        }

        return [
            'installed_found'   => $installedFound,
            'installed_version' => $searchVer,
            'latest_version'    => $latest['version'],
            'download_url'      => $latest['url'],
            'is_newer'          => $installedFound
                                && $this->compareFirmwareStrings($latest['version'], $searchVer) > 0
        ];
    }

    private function UpdateFirmwareVariables(?array $info): void
    {
        $enableFwVars = $this->ReadPropertyBoolean('EnableFirmwareVariables');
        if (!$enableFwVars) {
            return;
        }

        if ($info === null || !is_array($info) || !array_key_exists('installed_found', $info)) {
            // generischer Fehler
            $this->SetValue('FirmwareUpdateAvailable', false);
            $this->SetValue('FirmwareDownloadUrl', 'Fehler bei der Auswertung der README-Datei');
            return;
        }

        if (!$info['installed_found']) {
            $this->SetValue('FirmwareUpdateAvailable', false);
            $this->SetValue('FirmwareDownloadUrl', 'Firmware im README nicht gefunden');
            return;
        }

        // Ab hier: passende Tabelle gefunden
        $latest = $info['latest_version'] ?? null;
        $url    = $info['download_url'] ?? null;
        $isNewer = $info['is_newer'] ?? false;

        if (!$isNewer || $latest === null || $url === null) {
            $this->SetValue('FirmwareUpdateAvailable', false);
            $this->SetValue('FirmwareDownloadUrl', 'Keine neuere Firmware vorhanden');
        } else {
            $this->SetValue('FirmwareUpdateAvailable', true);
            $this->SetValue('FirmwareDownloadUrl', sprintf('<a href="%s" target="_blank">%s</a>', $url, $url));
        }
    }

    private function compareFirmwareStrings(string $a, string $b): int
    {
        // führendes "v" ignorieren
        if ($a !== '' && $a[0] === 'v') {
            $a = substr($a, 1);
        }
        if ($b !== '' && $b[0] === 'v') {
            $b = substr($b, 1);
        }

        // Hauptversion und Build trennen: 3.0.0.3471_2406116464
        [$va, $ba] = array_pad(explode('_', $a, 2), 2, '');
        [$vb, $bb] = array_pad(explode('_', $b, 2), 2, '');

        $partsA = array_map('intval', explode('.', $va));
        $partsB = array_map('intval', explode('.', $vb));

        $len = max(count($partsA), count($partsB));
        for ($i = 0; $i < $len; $i++) {
            $pa = $partsA[$i] ?? 0;
            $pb = $partsB[$i] ?? 0;
            if ($pa === $pb) {
                continue;
            }
            return $pa <=> $pb;
        }

        // gleiche Hauptversion → Build vergleichen
        $baInt = ctype_digit($ba) ? (int)$ba : 0;
        $bbInt = ctype_digit($bb) ? (int)$bb : 0;
        return $baInt <=> $bbInt;
    }

    public function FirmwareCheckTimer(): void
    {
        if (!$this->isActive() || !$this->ReadPropertyBoolean('EnableFirmwareVariables')) {
            $this->SetTimerInterval('FirmwareCheckTimer', 0);
            return;
        }

        $dev = $this->apiGetDevInfoFresh();
        if (!is_array($dev)) {
            $dev = [];
        }

        $info = $this->FirmwareCheck($dev);
        $this->UpdateFirmwareVariables($info);

        $this->WriteAttributeInteger('FirmwareLastCheckTs', time());
    }

    private function apiGetDevInfoFresh(): array
    {
        $attr     = 'DevInfoCache';
        $now      = time();
        $cameraIP = $this->ReadPropertyString('CameraIP');

        // Frische Abfrage
        $res = $this->apiCall([['cmd' => 'GetDevInfo']], 'DEVINFO', /*suppress*/ true);
        $this->SendDebug('DEVINFO', 'RAW: ' . print_r($res, true), 0);

        $devInfo = [];
        if (is_array($res) && isset($res[0]['code']) && $res[0]['code'] === 0) {
            $devInfo = $res[0]['value']['DevInfo'] ?? [];
        }

        // Cache aktualisieren
        $this->WriteAttributeString($attr, json_encode([
            'ts'      => $now,
            'ip'      => $cameraIP,
            'devInfo' => $devInfo,
        ]));

        return $devInfo;
    }

    private function getModelImageBase64(array $dev): ?string
    {
        if (empty($dev['model'])) {
            return null;
        }

        // Modellname aus DevInfo, evtl. "(IPC)" o.ä. abschneiden
        $modelName = preg_replace('/\s*\(.*$/', '', $dev['model']);

        // URL nach Reolink-Muster aufbauen
        $encodedModel = rawurlencode($modelName);
        $url          = 'https://home-cdn.reolink.us/wp-content/assets/app/model-images/' .
                        $encodedModel . '/product.png';

        $this->SendDebug('ModelImage', 'Lade Bild von: ' . $url, 0);

        $imgData = @file_get_contents($url);
        if ($imgData === false || $imgData === '') {
            $this->SendDebug('ModelImage', 'Download fehlgeschlagen', 0);
            return null;
        }

        // Bild nach Download massiv verkleinern (z.B. 4x kleiner)
        if (function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($imgData);
            if ($src !== false) {
                $srcWidth  = imagesx($src);
                $srcHeight = imagesy($src);

                if ($srcWidth > 0 && $srcHeight > 0) {
                    $factor    = 4; // 4x kleiner
                    $newWidth  = max(1, (int)round($srcWidth / $factor));
                    $newHeight = max(1, (int)round($srcHeight / $factor));

                    $this->SendDebug('ModelImage', sprintf(
                        'Resize von %dx%d auf %dx%d',
                        $srcWidth,
                        $srcHeight,
                        $newWidth,
                        $newHeight
                    ), 0);

                    $dst = imagecreatetruecolor($newWidth, $newHeight);

                    // Transparenz erhalten (PNG)
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);

                    imagecopyresampled(
                        $dst,
                        $src,
                        0,
                        0,
                        0,
                        0,
                        $newWidth,
                        $newHeight,
                        $srcWidth,
                        $srcHeight
                    );

                    ob_start();
                    imagepng($dst);
                    $imgData = ob_get_clean();

                    imagedestroy($dst);
                    imagedestroy($src);
                } else {
                    imagedestroy($src);
                }
            }
        } else {
            $this->SendDebug('ModelImage', 'GD/Image-Funktionen nicht verfügbar, kein Resize möglich', 0);
        }

        // Bild in Base64 wandeln
        $base64 = 'data:image/png;base64,' . base64_encode($imgData);

        return $base64;
    }

    private function apiGetDevInfoCached(bool $forceFresh = false): array
    {
        $attr     = 'DevInfoCache';
        $now      = time();
        $cameraIP = $this->ReadPropertyString('CameraIP');

        // 1) Cache lesen (nur wenn nicht "forceFresh")
        if (!$forceFresh) {
            $raw = @$this->ReadAttributeString($attr);
            if (is_string($raw) && $raw !== '') {
                $obj = @json_decode($raw, true);
                if (
                    is_array($obj)
                    && isset($obj['ts'], $obj['devInfo'], $obj['ip'])
                ) {
                    // Nur benutzen, wenn IP noch gleich und Cache < 1h
                    if (
                        $obj['ip'] === $cameraIP
                        && ($now - (int)$obj['ts']) < 3600
                        && !empty($obj['devInfo'])
                    ) {
                        return (array)$obj['devInfo'];
                    }
                }
            }
        }

        // 2) Frische Abfrage
        $res = $this->apiCall([['cmd' => 'GetDevInfo']], 'DEVINFO', /*suppress*/ true);

        $this->SendDebug('DEVINFO', 'RAW: ' . print_r($res, true), 0);

        $devInfo = [];
        if (is_array($res) && isset($res[0]['code']) && $res[0]['code'] === 0) {
            $devInfo = $res[0]['value']['DevInfo'] ?? [];
        }

        $this->SendDebug('DevInfo', 'Parsed: ' . print_r($devInfo, true), 0);

        // Nur cachen, wenn wirklich etwas drin ist
        if (!empty($devInfo)) {
            $this->WriteAttributeString($attr, json_encode([
                'ts'      => $now,
                'ip'      => $cameraIP,
                'devInfo' => $devInfo
            ]));
        }

        return $devInfo;
    }

    // ---------------------------
    // API-Fähigkeiten für Formular & Cache
    // ---------------------------

    private function getApiSupportFlags(): array
    {
        $ability = $this->apiGetAbilityCached();
        if (empty($ability)) {
            // Noch keine Ability (kein Token / keine Verbindung) -> nichts einschränken
            return [];
        }

        $chn = $ability['abilityChn'][0] ?? [];

        // Grobe Feature-Erkennung aus Ability
        $support = [
            // Spotlight / Weißlicht-LED
            'EnableApiWhiteLed' => (($chn['floodLight']['ver'] ?? 0) > 0)
                                || (($chn['whiteLed']['ver'] ?? 0) > 0),

            // IR-Licht
            'EnableApiIR'       => (($chn['irLights']['ver'] ?? 0) > 0)
                                || (($chn['led']['ver'] ?? 0) > 0),

            // E-Mail-Alarm
            'EnableApiEmail'    => (int)($ability['supportEmailEnable'] ?? 0) === 1,

            // FTP
            'EnableApiFTP'      => (($chn['ftp']['ver'] ?? 0) > 0),

            // Motion-Detection / Sensitivity
            'EnableApiSensitivity' =>
                                    (($chn['alarmMd']['ver'] ?? 0) > 0)
                                || (($chn['md']['ver'] ?? 0) > 0),

            // Sirene / AudioAlarm
            'EnableApiSiren'    => (($chn['AudioAlarm']['ver'] ?? 0) > 0)
                                || (($chn['audioAlarm']['ver'] ?? 0) > 0),

            // Recording
            'EnableApiRecord'   => (($chn['recCfg']['ver'] ?? 0) > 0),

            // PTZ
            'EnableApiPTZ'      => (($chn['ptz']['ver'] ?? 0) > 0),
        ];

        // Feincheck: apiProbe pro Domain (füllt auch deinen ApiVersionCache!)
        $probeMap = [
            'EnableApiEmail'  => ['domain' => 'email',  'cmdV20' => 'GetEmailV20',      'cmdLegacy' => 'GetEmail',       'action' => 0],
            'EnableApiFTP'    => ['domain' => 'ftp',    'cmdV20' => 'GetFtpV20',        'cmdLegacy' => 'GetFtp',         'action' => 0],
            'EnableApiRecord' => ['domain' => 'record', 'cmdV20' => 'GetRecV20',        'cmdLegacy' => 'GetRec',         'action' => 1],
            'EnableApiSiren'  => ['domain' => 'alarm',  'cmdV20' => 'GetAudioAlarmV20', 'cmdLegacy' => 'GetAudioAlarm',  'action' => 1],
            // WhiteLed nutzt bei dir sowieso nur GetWhiteLed, apiProbe ist hier im Prinzip nur "ping"
            'EnableApiWhiteLed' => ['domain' => 'spot', 'cmdV20' => 'GetWhiteLed',      'cmdLegacy' => 'GetWhiteLed',    'action' => 0],
        ];

        foreach ($probeMap as $propName => $p) {
            // Wenn Ability schon sagt „geht nicht“, spar dir den Probe
            if (isset($support[$propName]) && $support[$propName] === false) {
                continue;
            }

            $ver = $this->apiProbe($p['domain'], $p['cmdV20'], $p['cmdLegacy'], $p['action']);
            if ($ver === 'unsupported') {
                $support[$propName] = false;
            } elseif (!isset($support[$propName])) {
                $support[$propName] = true;
            }
            // Wichtig: apiProbe schreibt hier bereits in deinen ApiVersionCache rein.
        }

        return $support;
    }

    private function buildApiFeatureMatrix(): array
    {
        $ability = $this->apiGetAbilityCached();
        if (empty($ability)) {
            return [];
        }
        $chn = $ability['abilityChn'][0] ?? [];

        $rows = [];

        $defs = [
            'Email' => [
                'prop'   => 'EnableApiEmail',
                'label'  => 'E-Mail Alarm',
                'domain' => 'email',
                'cmdV20' => 'GetEmailV20',
                'cmdLegacy' => 'GetEmail',
                'action' => 0,
                'supported' => (int)($ability['supportEmailEnable'] ?? 0) === 1
            ],
            'FTP' => [
                'prop'   => 'EnableApiFTP',
                'label'  => 'FTP Upload',
                'domain' => 'ftp',
                'cmdV20' => 'GetFtpV20',
                'cmdLegacy' => 'GetFtp',
                'action' => 0,
                'supported' => (($chn['ftp']['ver'] ?? 0) > 0)
            ],
            'Record' => [
                'prop'   => 'EnableApiRecord',
                'label'  => 'Aufnahme / Record',
                'domain' => 'record',
                'cmdV20' => 'GetRecV20',
                'cmdLegacy' => 'GetRec',
                'action' => 1,
                'supported' => (($chn['recCfg']['ver'] ?? 0) > 0)
            ],
            'Siren' => [
                'prop'   => 'EnableApiSiren',
                'label'  => 'Sirene / AudioAlarm',
                'domain' => 'alarm',
                'cmdV20' => 'GetAudioAlarmV20',
                'cmdLegacy' => 'GetAudioAlarm',
                'action' => 1,
                'supported' => (($chn['AudioAlarm']['ver'] ?? 0) > 0)
                            || (($chn['audioAlarm']['ver'] ?? 0) > 0)
            ],
            'WhiteLed' => [
                'prop'   => 'EnableApiWhiteLed',
                'label'  => 'Spotlight / Weißlicht',
                'domain' => 'spot',
                'cmdV20' => 'GetWhiteLed',
                'cmdLegacy' => 'GetWhiteLed',
                'action' => 0,
                'supported' => (($chn['floodLight']['ver'] ?? 0) > 0)
                            || (($chn['whiteLed']['ver'] ?? 0) > 0)
            ],
        ];

        foreach ($defs as $key => $d) {
            $supported = $d['supported'];

            if (!$supported) {
                $rows[] = [
                    'label'     => $d['label'],
                    'supported' => 'nein',
                    'version'   => '-',
                ];
                continue;
            }

            // Erst versuchen, Version aus Cache zu holen
            $ver = $this->apiVersionGet($d['domain']);
            if ($ver === null) {
                // Falls noch nicht probiert, einmalig apiProbe
                $ver = $this->apiProbe($d['domain'], $d['cmdV20'], $d['cmdLegacy'], $d['action']);
            }

            $rows[] = [
                'label'     => $d['label'],
                'supported' => 'ja',
                'version'   => ($ver === 'v20') ? 'V20' :
                            (($ver === 'legacy') ? 'Legacy' :
                            (($ver === 'unsupported') ? '–' : '?')),
            ];
        }

        return $rows;
    }

    private function enrichFormWithApiSupport(array $form): array
    {
        $support = $this->getApiSupportFlags();
        if (empty($support)) {
            // Noch keine Infos -> nichts ausblenden
            return $form;
        }

        if (!isset($form['elements']) || !is_array($form['elements'])) {
            return $form;
        }

        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') !== 'ExpansionPanel') {
                continue;
            }
            if (($el['caption'] ?? '') !== 'API-Funktionen') {
                continue;
            }
            if (!isset($el['items']) || !is_array($el['items'])) {
                continue;
            }

            foreach ($el['items'] as &$item) {
                $name = $item['name'] ?? null;
                if ($name === null) {
                    continue;
                }
                if (!array_key_exists($name, $support)) {
                    continue;
                }

                if ($support[$name] === false) {
                    // Variante A: ausblenden
                    $item['visible'] = false;

                    // Variante B (stattdessen, wenn du lieber alles siehst, aber deaktiviert):
                    // $item['enabled'] = false;
                    // $item['caption'] .= ' (nicht unterstützt)';
                }
            }
            unset($item);
        }
        unset($el);

        // Optional: Feature-Matrix anhängen
        $rows = $this->buildApiFeatureMatrix();
        if (!empty($rows)) {
            $form['elements'][] = [
                'type'    => 'List',
                'name'    => 'ApiFeatureMatrix',
                'caption' => 'Erkannte API-Funktionen',
                'columns' => [
                    [ 'caption' => 'Funktion',    'name' => 'label',     'width' => '250px' ],
                    [ 'caption' => 'Unterstützt', 'name' => 'supported', 'width' => '100px' ],
                    [ 'caption' => 'Version',     'name' => 'version',   'width' => '100px' ],
                ],
                'values'  => $rows,
            ];
        }

        return $form;
    }

    private function getLocalIPv4(string $probe='8.8.8.8:53'): string {
        $ip = '127.0.0.1';
        if ($sock = @stream_socket_client('udp://'.$probe, $e1, $e2, 1)) {
            $name = stream_socket_get_name($sock, false);
            fclose($sock);
            if (is_string($name) && ($p = strrpos($name, ':')) !== false) {
                $ip = substr($name, 0, $p);
            }
        }
        return $ip;
    }

    private function BuildWebhookFullUrl(string $hookPath): string
    {
        $host = $this->getLocalIPv4();   
        $port = 3777;   
        return "http://{$host}:{$port}{$hookPath}";
    }

    public function ProcessHookData(): void
    {
        if (!$this->ReadPropertyBoolean("InstanceStatus") || $this->GetStatus() !== 102) {
            while (ob_get_level() > 0) { @ob_end_clean(); }
            header('HTTP/1.1 204 No Content');
            return;
        }
        while (ob_get_level() > 0) { @ob_end_clean(); }

        $ptz = null;
        $raw = @file_get_contents('php://input');

        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['ptz'])) {
                $ptz = (string)$data['ptz'];
                if (isset($data['id']))   $_REQUEST['id'] = $data['id'];
                if (isset($data['name'])) $_REQUEST['name'] = $data['name'];
            }
        }
        if ($ptz === null) {
            if (isset($_POST['ptz'])) { $ptz = (string)$_POST['ptz']; }
            elseif (isset($_GET['ptz'])) { $ptz = (string)$_GET['ptz']; }
        }

        if ($ptz !== null) {
            $this->dbg('WEBHOOK', 'PTZ-Call', ['ptz' => $ptz, 'id' => $_REQUEST['id'] ?? '', 'name' => $_REQUEST['name'] ?? '']);
            $ok = $this->HandlePtzCommand($ptz);
            header('Content-Type: text/plain; charset=utf-8');
            echo $ok ? "OK" : "ERROR";
            return;
        }

        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $this->dbg('WEBHOOK', 'Alarmdaten empfangen', $data);
                $this->ProcessAllData($data);
                header('Content-Type: text/plain; charset=utf-8');
                echo "OK";
                return;
            }
        }

        $this->dbg('WEBHOOK', 'No PTZ / no usable payload');
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERROR";
    }

    // ---------------------------
    // Bewegungen / Snapshots / Archiv
    // ---------------------------

    private function ProcessAllData($data)
    {
        if (!isset($data['alarm']['type'])) return;
        $type = $data['alarm']['type'];

        switch ($type) {
            case "PEOPLE":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Person", 21);
                $this->SetMoveTimer("Person");
                break;
            case "ANIMAL":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Tier", 26);
                $this->SetMoveTimer("Tier");
                break;
            case "VEHICLE":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Fahrzeug", 31);
                $this->SetMoveTimer("Fahrzeug");
                break;
            case "MD":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Bewegung", 36);
                $this->SetMoveTimer("Bewegung");
                break;
            case "VISITOR":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Besucher", 41);
                $this->SetMoveTimer("Besucher");
                break;
            case "TEST":
                if ($this->ReadPropertyBoolean("ShowSnapshots")) $this->CreateSnapshotAtPosition("Test", 46);
                if ($this->ReadPropertyBoolean("ShowTestElements")) $this->SetMoveTimer("Test");
                break;
        }
    }

    private function SetMoveTimer(string $ident)
    {
        $timerName = $ident . "_Reset";
        $this->dbg('POLLING', "Setze '$ident' auf true");
        $this->SetValue($ident, true);
        $this->SetTimerInterval($timerName, 5000);
    }

    public function ResetMoveTimer(string $ident): void
    {
        $timerName = $ident . "_Reset";
        $this->dbg('POLLING', "Reset '$ident' → false");
        $this->SetValue($ident, false);
        $this->SetTimerInterval($timerName, 0);
    }

    private function CreateMoveVariables()
    {
        $this->RegisterVariableBoolean("Person",   "Person",             "~Motion", 20);
        $this->RegisterVariableBoolean("Tier",     "Tier",               "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug",           "~Motion", 30);
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion", 35);
        $this->RegisterVariableBoolean("Besucher", "Besucher",           "~Motion", 40);
        $this->RegisterVariableBoolean("Test",     "Test",               "~Motion", 45);
    }

    private function RemoveMoveVariables()
    {
        foreach (["Person","Tier","Fahrzeug","Bewegung","Besucher","Test"] as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false) $this->UnregisterVariable($ident);
        }
    }

    private function CreateTestElements()
    {
        $this->RegisterVariableBoolean("Test", "Test", "~Motion", 50);

        if (!IPS_ObjectExists(@$this->GetIDForIdent("Snapshot_Test"))) {
            $mediaID = IPS_CreateMedia(1);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, "Snapshot_Test");
            IPS_SetName($mediaID, "Snapshot Test");
            IPS_SetMediaCached($mediaID, false);
        }

        if (!IPS_ObjectExists(@$this->GetIDForIdent("Archive_Test"))) {
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, "Archive_Test");
            IPS_SetName($categoryID, "Bildarchiv Test");
        }
    }

    private function RemoveTestElements()
    {
        $id = @$this->GetIDForIdent("Test");
        if ($id) $this->UnregisterVariable("Test");

        $mid = @$this->GetIDForIdent("Snapshot_Test");
        if ($mid) IPS_DeleteMedia($mid, true);

        $cid = @$this->GetIDForIdent("Archive_Test");
        if ($cid) {
            foreach (IPS_GetChildrenIDs($cid) as $childID) {
                IPS_DeleteMedia($childID, true);
            }
            IPS_DeleteCategory($cid);
        }
    }

    private function CreateVisitorElements()
    {
        $this->RegisterVariableBoolean("Besucher", "Besucher erkannt", "~Motion", 50);

        if (!IPS_ObjectExists(@$this->GetIDForIdent("Snapshot_Besucher"))) {
            $mediaID = IPS_CreateMedia(1);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, "Snapshot_Besucher");
            IPS_SetName($mediaID, "Snapshot Besucher");
            IPS_SetMediaCached($mediaID, false);
        }

        if (!IPS_ObjectExists(@$this->GetIDForIdent("Archive_Besucher"))) {
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, "Archive_Besucher");
            IPS_SetName($categoryID, "Bildarchiv Besucher");
        }
    }

    private function RemoveVisitorElements()
    {
        $id = @$this->GetIDForIdent("Besucher");
        if ($id) $this->UnregisterVariable("Besucher");

        $mid = @$this->GetIDForIdent("Snapshot_Besucher");
        if ($mid) IPS_DeleteMedia($mid, true);

        $cid = @$this->GetIDForIdent("Archive_Besucher");
        if ($cid) {
            foreach (IPS_GetChildrenIDs($cid) as $childID) {
                IPS_DeleteMedia($childID, true);
            }
            IPS_DeleteCategory($cid);
        }
    }

    private function CreateSnapshotAtPosition(string $booleanIdent, int $position)
    {
        if (!$this->ReadPropertyBoolean("ShowTestElements") && $booleanIdent === "Test") return;
        if (!$this->ReadPropertyBoolean("ShowVisitorElements") && $booleanIdent === "Besucher") return;

        $snapshotIdent = "Snapshot_" . $booleanIdent;
        $mediaID = @$this->GetIDForIdent($snapshotIdent);

        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(1);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $snapshotIdent);
            IPS_SetPosition($mediaID, $position);
            IPS_SetName($mediaID, "Snapshot von " . $booleanIdent);
            IPS_SetMediaCached($mediaID, false);
            $this->dbg('SNAPSHOT', "Neues Medienobjekt", ['ident' => $snapshotIdent, 'mediaID' => $mediaID]);
        }

        $snapshotUrl = $this->GetSnapshotURL(); 
        $fileName = $booleanIdent . "_" . $mediaID . ".jpg";
        $filePath = IPS_GetKernelDir() . "media/" . $fileName;

        $this->dbg('SNAPSHOT', 'Abrufen', ['url' => $snapshotUrl]);

        // Snapshot per cURL holen – funktioniert für HTTP und HTTPS
        $ch = curl_init($snapshotUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,

            // für HTTPS mit selbstsigniertem Zertifikat
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);

        $imageData = curl_exec($ch);
        if ($imageData === false) {
            $err = curl_error($ch);
            $this->dbg('SNAPSHOT', 'cURL-Fehler beim Abrufen', $err);
            curl_close($ch);
            $this->dbg('SNAPSHOT', "Fehler beim Abrufen", ['boolean' => $booleanIdent]);
        } else {
            curl_close($ch);

            IPS_SetMediaFile($mediaID, $filePath, false);
            IPS_SetMediaContent($mediaID, base64_encode($imageData));
            IPS_SendMediaEvent($mediaID);

            $this->dbg('SNAPSHOT', "Erstellt", ['boolean' => $booleanIdent, 'file' => $fileName]);

            if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                $archiveCategoryID = $this->CreateOrGetArchiveCategory($booleanIdent);
                $this->CreateArchiveSnapshot($booleanIdent, $archiveCategoryID);
            }
        }
    }

    private function RemoveSnapshots()
    {
        foreach (["Snapshot_Person","Snapshot_Tier","Snapshot_Fahrzeug","Snapshot_Test","Snapshot_Besucher","Snapshot_Bewegung"] as $ident) {
            $mid = @$this->GetIDForIdent($ident);
            if ($mid) IPS_DeleteMedia($mid, true);
        }
    }

    private function CreateOrUpdateArchives()
    {
        foreach (["Person","Tier","Fahrzeug","Bewegung","Besucher","Test"] as $cat) {
            $this->CreateOrGetArchiveCategory($cat);
        }
    }

    private function RemoveArchives()
    {
        foreach (["Person","Tier","Fahrzeug","Bewegung","Besucher","Test"] as $cat) {
            $archiveIdent = "Archive_" . $cat;
            $cid = @$this->GetIDForIdent($archiveIdent);
            if ($cid && IPS_ObjectExists($cid)) {
                foreach (IPS_GetChildrenIDs($cid) as $childID) {
                    if (IPS_MediaExists($childID)) {
                        IPS_DeleteMedia($childID, true);
                    } else {
                        @IPS_DeleteObject($childID);
                    }
                }
                IPS_DeleteCategory($cid);
                $this->dbg('SNAPSHOT', 'Archiv-Kategorie entfernt', ['ident' => $archiveIdent, 'id' => $cid]);
            }
        }
    }

    private function CreateOrGetArchiveCategory(string $booleanIdent)
    {
        $archiveIdent = "Archive_" . $booleanIdent;
        $categoryID = @$this->GetIDForIdent($archiveIdent);
        if ($categoryID === false) {
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, $archiveIdent);
            IPS_SetName($categoryID, "Bildarchiv " . $booleanIdent);
            $pos = [
                "Person" => 22, "Tier" => 27, "Fahrzeug" => 32,
                "Bewegung" => 37, "Besucher" => 42, "Test" => 47
            ][$booleanIdent] ?? 99;
            IPS_SetPosition($categoryID, $pos);
        }
        return $categoryID;
    }

    private function PruneArchive(int $categoryID, string $booleanIdent)
    {
        $maxImages = max(0, (int)$this->ReadPropertyInteger("MaxArchiveImages"));
        $children  = IPS_GetChildrenIDs($categoryID);

        $this->dbg('SNAPSHOT', 'Archiv prüfen', ['cat' => $booleanIdent, 'anzahl' => count($children), 'max' => $maxImages]);

        if (count($children) > $maxImages) {
            usort($children, function ($a, $b) {
                $A = @IPS_GetObject($a) ?: ['ObjectPosition' => 0];
                $B = @IPS_GetObject($b) ?: ['ObjectPosition' => 0];
                return $B['ObjectPosition'] <=> $A['ObjectPosition'];
            });

            while (count($children) > $maxImages) {
                $oldestID = array_shift($children);
                if (@IPS_ObjectExists($oldestID) && IPS_MediaExists($oldestID)) {
                    IPS_DeleteMedia($oldestID, true);
                    $this->dbg('SNAPSHOT', 'Archiv: entfernt', ['id' => $oldestID]);
                }
            }
        }
    }

    private function CreateArchiveSnapshot(string $booleanIdent, int $categoryID)
    {
        $archiveIdent = "Archive_" . $booleanIdent . "_" . time();
        $mediaID = IPS_CreateMedia(1);
        IPS_SetParent($mediaID, $categoryID);
        IPS_SetIdent($mediaID, $archiveIdent);
        IPS_SetPosition($mediaID, -time());
        IPS_SetName($mediaID, $booleanIdent . " " . date("Y-m-d H:i:s"));
        IPS_SetMediaCached($mediaID, false);

        $snapshotUrl = $this->GetSnapshotURL();
        $archiveImagePath = IPS_GetKernelDir() . "media/" . $booleanIdent . "_" . $mediaID . ".jpg";

        $this->dbg('SNAPSHOT', 'Abrufen', ['url' => $snapshotUrl]);

        // Snapshot mit cURL holen (HTTPS mit Self-Signed zulassen)
        $ch = curl_init($snapshotUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 8,
        ]);
        $imageData = curl_exec($ch);
        if ($imageData === false) {
            $err = curl_error($ch);
            $this->dbg('SNAPSHOT', 'cURL-Fehler beim Abrufen', $err);
            curl_close($ch);
            $this->dbg('SNAPSHOT', "Archivbild fehlgeschlagen", ['boolean' => $booleanIdent]);
        } else {
            curl_close($ch);

            IPS_SetMediaFile($mediaID, $archiveImagePath, false);
            IPS_SetMediaContent($mediaID, base64_encode($imageData));
            IPS_SendMediaEvent($mediaID);
            $this->dbg('SNAPSHOT', 'Archivbild erstellt', ['boolean' => $booleanIdent, 'mediaID' => $mediaID]);
            $this->PruneArchive($categoryID, $booleanIdent);
        }
    }

    private function CreateOrUpdateStream(string $ident, string $name)
    {
        $mediaID = $this->GetIDForIdent($ident);
        if ($mediaID === 0) {
            $mediaID = IPS_CreateMedia(3);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $name);
            IPS_SetPosition($mediaID, 10);
            IPS_SetMediaCached($mediaID, true);
        }
        IPS_SetMediaFile($mediaID, $this->GetStreamURL(), false);
    }

    private function GetStreamURL(): string
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));
        $streamType = $this->ReadPropertyString("StreamType");
        return $streamType === "main"
            ? "rtsp://$username:$password@$cameraIP:554"
            : "rtsp://$username:$password@$cameraIP:554/h264Preview_01_sub";
    }

    private function GetSnapshotURL(): string
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));

        // Gleiche Logik wie bei apiBase(): HTTP oder HTTPS je nach Checkbox
        $useHttps = $this->ReadPropertyBoolean('UseHttps');
        $scheme   = $useHttps ? 'https' : 'http';

        return sprintf(
            '%s://%s/cgi-bin/api.cgi?cmd=Snap&user=%s&password=%s&width=1024&height=768',
            $scheme,
            $cameraIP,
            $username,
            $password
        );
    }

    // ---------------------------
    // Polling (AI State)
    // ---------------------------

    public function Polling(): void
    {
        if (!$this->isActive() || !$this->ReadPropertyBoolean("EnablePolling")) {
            $this->SetTimerInterval("PollingTimer", 0);
            return;
        }
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));

        $url = $this->apiBase() . "/api.cgi?cmd=GetAiState&rs=&user=$username&password=$password";
        $this->dbg('POLLING', 'Abruf', ['url' => $url]);

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->dbg("POLLING", "Fehler beim Abrufen der Daten");
            return;
        }

        $this->dbg("POLLING", "Rohdaten", $this->redactDeep($response));

        $data = json_decode($response, true);
        if ($data === null || !isset($data[0]['value'])) {
            $this->dbg("POLLING", "Ungueltige Daten", $response);
            return;
        }

        $aiState = $data[0]['value'];
        $this->PollingUpdateState("dog_cat", $aiState['dog_cat']['alarm_state'] ?? 0);
        $this->PollingUpdateState("people",  $aiState['people']['alarm_state']  ?? 0);
        $this->PollingUpdateState("vehicle", $aiState['vehicle']['alarm_state'] ?? 0);
    }

    private function PollingUpdateState(string $type, int $state)
    {
        $mapping = [ "dog_cat" => "Tier", "people" => "Person", "vehicle" => "Fahrzeug" ];
        if (!isset($mapping[$type])) return;

        $ident = $mapping[$type];
        $variableID = @$this->GetIDForIdent($ident);
        if ($variableID === false) return;

        $currentValue = (bool)GetValue($variableID);
        $newValue     = ($state == 1);

        if ($currentValue !== $newValue) {
            $this->SetValue($ident, $newValue);
            $this->dbg('POLLING', "Setze '$ident'", ['value' => $newValue]);
            $timerName = $ident . "_Reset";
            if ($newValue) {
                $this->SetTimerInterval($timerName, 5000);
                $this->dbg("POLLING", "Snapshot trigger fuer '$ident'");
                $this->CreateSnapshotAtPosition($ident, IPS_GetObject($variableID)['ObjectPosition'] + 1);
            } else {
                $this->SetTimerInterval($timerName, 0);
            }
        }
    }

    // ---------------------------
    // API Variablen erstellen/löschen
    // ---------------------------

    private function CreateOrUpdateApiVariablesUnified(): void
    {
        // -------- IR (Infrared) --------
        if ($this->ReadPropertyBoolean("EnableApiIR")) {
            if (!IPS_VariableProfileExists("REOCAM.IR")) {
                IPS_CreateVariableProfile("REOCAM.IR", 1); 
            }
            IPS_SetVariableProfileValues("REOCAM.IR", 0, 2, 0);
            IPS_SetVariableProfileAssociation("REOCAM.IR", 0, "Aus",  "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.IR", 1, "An",   "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.IR", 2, "Auto", "", -1);

            $this->RegisterVariableInteger("IRLights", "IR Beleuchtung", "REOCAM.IR", 0);
            $this->EnableAction("IRLights");
        } else {
            $this->UnregisterVariable("IRLights");
        }

        // -------- White LED --------
        if ($this->ReadPropertyBoolean("EnableApiWhiteLed")) {
            if (!IPS_VariableProfileExists("REOCAM.WLED")) {
                IPS_CreateVariableProfile("REOCAM.WLED", 1); 
            }
            IPS_SetVariableProfileValues("REOCAM.WLED", 0, 2, 0);
            IPS_SetVariableProfileAssociation("REOCAM.WLED", 0, "Aus", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.WLED", 1, "Automatisch", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.WLED", 2, "Zeitabhängig", "", -1);

            $this->RegisterVariableBoolean("WhiteLed", "LED Status", "~Switch", 1);
            $this->EnableAction("WhiteLed");

            $this->RegisterVariableInteger("Mode", "LED Modus", "REOCAM.WLED", 1);
            $this->EnableAction("Mode");

            $this->RegisterVariableInteger("Bright", "LED Helligkeit", "~Intensity.100", 1);
            $this->EnableAction("Bright");
        } else {
            $this->UnregisterVariable("WhiteLed");
            $this->UnregisterVariable("Mode");
            $this->UnregisterVariable("Bright");
        }

        // -------- Email --------
        if ($this->ReadPropertyBoolean("EnableApiEmail")) {
            if (!IPS_VariableProfileExists("REOCAM.EmailInterval")) {
                IPS_CreateVariableProfile("REOCAM.EmailInterval", 1);
            }
            IPS_SetVariableProfileValues("REOCAM.EmailInterval", 30, 1800, 0);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 30,   "30 Sek.",    "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 60,   "1 Minute",   "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 300,  "5 Minuten",  "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 600,  "10 Minuten", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 1800, "30 Minuten", "", -1);

            if (!IPS_VariableProfileExists("REOCAM.EmailContent")) {
                IPS_CreateVariableProfile("REOCAM.EmailContent", 1);
            }
            IPS_SetVariableProfileValues("REOCAM.EmailContent", 0, 3, 0);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 0, "Text",             "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 1, "Bild (ohne Text)", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 2, "Text + Bild",      "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 3, "Text + Video",     "", -1);

            $this->RegisterVariableBoolean("EmailNotify", "E-Mail Alarm", "~Switch", 2);
            $this->EnableAction("EmailNotify");

            $this->RegisterVariableInteger("EmailInterval", "E-Mail Intervall", "REOCAM.EmailInterval", 2);
            $this->EnableAction("EmailInterval");

            $this->RegisterVariableInteger("EmailContent", "E-Mail Inhalt", "REOCAM.EmailContent", 2);
            $this->EnableAction("EmailContent");
        } else {
            $this->UnregisterVariable("EmailNotify");
            $this->UnregisterVariable("EmailInterval");
            $this->UnregisterVariable("EmailContent");
        }

        // -------- PTZ (HTML Box) --------
        if ($this->ReadPropertyBoolean("EnableApiPTZ")) {
            $this->RegisterVariableString("PTZ_HTML", "PTZ", "~HTMLBox", 9);
        } else {
            $this->UnregisterVariable("PTZ_HTML");
        }

        // -------- FTP --------
        if ($this->ReadPropertyBoolean("EnableApiFTP")) {
            $this->RegisterVariableBoolean("FTPEnabled", "FTP", "~Switch", 3);
            $this->EnableAction("FTPEnabled");
        } else {
            $this->UnregisterVariable("FTPEnabled");
        }

        // -------- Bewegungssensitivität (1..50) --------
        if ($this->ReadPropertyBoolean("EnableApiSensitivity")) {
            if (!IPS_VariableProfileExists("REOCAM.Sensitivity50")) {
                IPS_CreateVariableProfile("REOCAM.Sensitivity50", 1); // Integer
            }
            IPS_SetVariableProfileValues("REOCAM.Sensitivity50", 1, 50, 1);

            $this->RegisterVariableInteger("MdSensitivity", "Bewegung Sensitivität", "REOCAM.Sensitivity50", 4);
            $this->EnableAction("MdSensitivity");
        } else {
            $this->UnregisterVariable("MdSensitivity");
        }

        // -------- Sirene--------
        if ($this->ReadPropertyBoolean("EnableApiSiren")) {
            $this->RegisterVariableBoolean("SirenEnabled", "Sirene", "~Switch", 5);
            $this->EnableAction("SirenEnabled");

            if (!IPS_VariableProfileExists("REOCAM.SirenAction")) {
                IPS_CreateVariableProfile("REOCAM.SirenAction", 1); // Integer
                }
                IPS_SetVariableProfileValues("REOCAM.SirenAction", 0, 100, 0);
                IPS_SetVariableProfileAssociation("REOCAM.SirenAction", 100, "Start (manuell)", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.SirenAction", 0,   "Stop",            "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.SirenAction", 1,   "1× abspielen",    "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.SirenAction", 2,   "2× abspielen",    "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.SirenAction", 3,   "3× abspielen",    "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.SirenAction", 4,   "4× abspielen",    "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.SirenAction", 5,   "5× abspielen",    "", -1);
            
            $this->RegisterVariableInteger("SirenAction", "Sirenenaktion", "REOCAM.SirenAction", 5);
            $this->EnableAction("SirenAction");

        } else {
            $this->UnregisterVariable("SirenEnabled");
            $this->UnregisterVariable("SirenAction");
        }

        // -------- Recording / Schedule --------
        if ($this->ReadPropertyBoolean("EnableApiRecord")) {
            $this->RegisterVariableBoolean("RecEnabled", "Kameraaufzeichnung", "~Switch", 6);
            $this->EnableAction("RecEnabled");
        } else {
            $this->UnregisterVariable("RecEnabled");
        }

        // -------- Kamera online --------
        if (@$this->GetIDForIdent('KameraOnline') === false) {
            $this->RegisterVariableBoolean('KameraOnline', 'Kamera online', '~Alert.Reversed', 11);
            $this->SetValue('KameraOnline', false);
        }

        // -------- Firmwarevariablen--------
        if ($this->ReadPropertyBoolean("EnableFirmwareVariables")) {
            $this->RegisterVariableBoolean("FirmwareUpdateAvailable", "Neue Firmware vorhanden", "~Switch", 12);
            $this->RegisterVariableString("FirmwareDownloadUrl", "Firmware Download", "~HTMLBox", 13);

        } else {
            $this->UnregisterVariable("FirmwareUpdateAvailable");
            $this->UnregisterVariable("FirmwareDownloadUrl");
        }
    }

    // ---------------------------
    // API / HTTP / Token
    // ---------------------------

    public function ResetApiCache(): void
    {
        $this->WriteAttributeString('ApiVersionCache', '{}');
        $this->WriteAttributeString('ApiCache', '{}');
        $this->SendDebug('API', 'Cache manuell gelöscht', 0);
    }

    public function GetToken(): void
    {
        if (!$this->isActive()) {
            $this->dbg('TOKEN', 'Abgebrochen: Instanz inaktiv');
            $this->SetTimerInterval("TokenRenewalTimer", 0);
            return;
        }
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");
        $cameraIP = trim($this->ReadPropertyString('CameraIP'));
        if ($cameraIP === "" || $username === "" || $password === "") {
            $this->dbg('TOKEN', 'Abgebrochen: Unvollständige Einstellungen');
            return;
        }

        $semName = "REOCAM_{$this->InstanceID}_GetToken";
        $entered = function_exists('IPS_SemaphoreEnter') ? IPS_SemaphoreEnter($semName, 5000) : true;
        if (!$entered) {
            $this->dbg('TOKEN', 'Übersprungen: anderer Login aktiv');
            return;
        }

        $this->WriteAttributeBoolean("TokenRefreshing", true);
        try {
            $url = $this->apiBase() . "/api.cgi?cmd=Login";
            $payload = [[
                "cmd"   => "Login",
                "param" => ["User" => [
                    "Version"  => "0",
                    "userName" => $username,
                    "password" => $password
                ]]
            ]];

            $this->dbg('TOKEN', 'Login', ['url' => $url]);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 8
            ]);
            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                $this->dbg('TOKEN', 'cURL-Fehler', $err);
                $this->LogMessage("Reolink/TOKEN: cURL-Fehler beim Login: $err", KL_ERROR);
                $this->WriteAttributeInteger('LastTokenErrorTs', time());
                return;
            }

            curl_close($ch);

            $this->dbg('TOKEN', 'RAW', $this->redactDeep($response));

            $responseData = json_decode($response, true);
            $token = $responseData[0]['value']['Token']['name'] ?? null;
            if (!is_string($token) || $token === "") {
                $this->dbg('TOKEN', 'Ungueltige Antwort (kein Token)', $responseData);
                $this->LogMessage("Reolink/TOKEN: Fehler beim Abrufen des Tokens: ".$response, KL_ERROR);
                $this->WriteAttributeInteger('LastTokenErrorTs', time());
                return;
            }


            $this->WriteAttributeString("ApiToken", $token);
            $this->WriteAttributeInteger("ApiTokenExpiresAt", time() + 3600 - 5);
            $this->WriteAttributeInteger('LastTokenErrorTs', 0); // Fehler-Flag zurücksetzen
            $this->SetTimerInterval("TokenRenewalTimer", 3000 * 1000);
            $this->dbg('TOKEN', 'Token gespeichert; Erneuerungstimer gesetzt');
        } finally {
            $this->WriteAttributeBoolean("TokenRefreshing", false);
            if (function_exists('IPS_SemaphoreLeave')) {
                IPS_SemaphoreLeave($semName);
            }
        }
    }

    public function ExecuteApiRequests(bool $force = false)
    {
        // Instanz aktiv?
        if (!$this->isActive()) {
            return;
        }

        // Online-Status aktualisieren (z.B. Ping / Erreichbarkeit)
        $this->UpdateOnlineStatus();

        // Wenn Kamera als offline markiert ist, keine API-Requests ausführen
        $onlineId = @$this->GetIDForIdent('KameraOnline');
        if ($onlineId !== false && !GetValueBoolean($onlineId)) {
            $this->dbg('API', 'Abgebrochen: Kamera offline, keine API-Requests');
            return;
        }

        // Ohne gültigen Token machen alle weiteren API-Aufrufe keinen Sinn
        if (!$this->apiEnsureToken()) {
            return;
        }

        $sem = "REOCAM_{$this->InstanceID}_Exec";
        if (function_exists('IPS_SemaphoreEnter')) {
            if (!IPS_SemaphoreEnter($sem, 2000)) {
                return;
            }
        }

        try {
            $last = (int)($this->ReadAttributeInteger('ExecLastTs') ?? 0);
            $now  = time();

            // Minimalabstand zwischen zwei Läufen (1 Sekunde)
            if (!$force && ($now - $last) < 1) {
                return;
            }
            $this->WriteAttributeInteger('ExecLastTs', $now);

            // -------------------------------------------------
            // DevInfo-Cache alle 10 Minuten aktualisieren
            // (damit z.B. nach Firmware-Downgrade/-Upgrade die Daten
            //  im Formular und für den Firmwarecheck frisch sind)
            // -------------------------------------------------
            $lastDev = (int)$this->ReadAttributeInteger('DevInfoLastRefresh');
            if (($now - $lastDev) > 600) { // 600 Sekunden = 10 Minuten
                $this->apiGetDevInfoCached(true); // holt frische Daten und schreibt Cache
                $this->WriteAttributeInteger('DevInfoLastRefresh', $now);
            }

            // -------------------------------------------------
            // API-abhängige Features nach Konfiguration
            // -------------------------------------------------
            if ($this->ReadPropertyBoolean("EnableApiWhiteLed")) {
                $this->UpdateWhiteLedStatus();
            }
            if ($this->ReadPropertyBoolean("EnableApiEmail")) {
                $this->EmailApply(null, null, null);
            }
            if ($this->ReadPropertyBoolean("EnableApiPTZ")) {
                $this->CreateOrUpdatePTZHtml(false);
            }
            if ($this->ReadPropertyBoolean("EnableApiFTP")) {
                $this->UpdateFtpStatus();
            }
            if ($this->ReadPropertyBoolean("EnableApiSensitivity")) {
                $this->UpdateMdSensitivityStatus();
            }
            if ($this->ReadPropertyBoolean("EnableApiSiren")) {
                $this->UpdateSirenStatus();
            }
            if ($this->ReadPropertyBoolean("EnableApiRecord")) {
                $this->UpdateRecStatus();
            }
            if ($this->ReadPropertyBoolean("EnableApiIR")) {
                $this->UpdateIrStatus();
            }

        } finally {
            if (function_exists('IPS_SemaphoreLeave')) {
                IPS_SemaphoreLeave($sem);
            }
        }
    }

    private function apiBase(): string
    {
        // IP der Kamera
        $ip = trim($this->ReadPropertyString('CameraIP'));

        // Checkbox im Formular: HTTPS verwenden
        $useHttps = $this->ReadPropertyBoolean('UseHttps');

        $scheme = $useHttps ? 'https' : 'http';

        // Basis-URL OHNE /api.cgi
        // => http(s)://IP
        return sprintf('%s://%s', $scheme, $ip);
    }

    private function apiHttpPostJson(string $url, array $payload, string $topic = 'API', bool $suppressError = false): ?array
    {
        $this->dbg($topic, "HTTP POST", ['url' => $url]);
        $this->dbg($topic, "REQUEST", $payload);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),

            // >>> wichtige Timeouts <<<
            CURLOPT_CONNECTTIMEOUT => 5,  // Sekunden für Verbindungsaufbau
            CURLOPT_TIMEOUT        => 8,  // maximale Gesamtdauer
            // wenn du es noch aggressiver willst:
            // CURLOPT_CONNECTTIMEOUT_MS => 3000,
            // CURLOPT_TIMEOUT_MS        => 5000,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $errno = curl_errno($ch);
            $err   = curl_error($ch);
            $info  = curl_getinfo($ch);
            curl_close($ch);

            if (!$suppressError) {
                $this->dbg($topic, "cURL error", [
                    'errno' => $errno,
                    'error' => $err,
                    'info'  => $info
                ]);
                $this->LogMessage("Reolink/$topic: cURL-Fehler: [$errno] $err", KL_ERROR);
            }
            return null;
        }
        curl_close($ch);

        $this->dbg($topic, "RAW", $this->redactDeep($raw));

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function apiEnsureToken(): bool
    {
        if (!$this->isActive()) {
            return false;
        }

        // Wenn es eine KameraOnline-Variable gibt und sie FALSE ist:
        // => gar nicht erst versuchen, einen Token zu holen.
        $onlineId = @$this->GetIDForIdent('KameraOnline');
        if ($onlineId !== false && !GetValueBoolean($onlineId)) {
            $this->dbg('TOKEN', 'Abgebrochen: Kamera offline, kein Token-Versuch');
            return false;
        }

        $token = $this->ReadAttributeString("ApiToken");
        $exp   = (int)$this->ReadAttributeInteger("ApiTokenExpiresAt");
        $now   = time();

        // Token fehlt oder läuft in Kürze ab
        if ($token === "" || $now >= ($exp - 30)) {

            // Login-Backoff: wenn der letzte Fehler < 60s her ist, NICHT erneut versuchen
            $lastError = (int)$this->ReadAttributeInteger('LastTokenErrorTs');
            if ($lastError !== 0 && ($now - $lastError) < 60) {
                $this->dbg('TOKEN', 'Letzter Login-Fehler < 60s her – überspringe neuen Versuch');
                return false;
            }

            $this->GetToken();
        }

        return $this->ReadAttributeString("ApiToken") !== "";
    }

    private function apiCall(array $cmdPayload, string $topic = 'API', bool $suppressError = false): ?array
    {
        if (!$this->isActive()) {
            return null;
        }
        if (!$this->apiEnsureToken()) {
            return null;
        }

        $token = $this->ReadAttributeString("ApiToken");

        // NEU: /api.cgi hier anhängen
        $url   = $this->apiBase() . "/api.cgi?token={$token}";
        $resp  = $this->apiHttpPostJson($url, $cmdPayload, $topic, $suppressError);
        if (!$resp) {
            return null;
        }

        if (isset($resp[0]['code']) && (int)$resp[0]['code'] === 0) {
            return $resp;
        }

        $rsp = $resp[0]['error']['rspCode'] ?? null;
        if ((int)$rsp === -6) {
            $this->dbg($topic, "Auth -6 → Token Refresh + Retry");
            $this->GetToken();
            $token2 = $this->ReadAttributeString("ApiToken");
            if ($token2) {
                // RETRY-URL ebenfalls mit /api.cgi
                $url2  = $this->apiBase() . "/api.cgi?token={$token2}";
                $resp2 = $this->apiHttpPostJson($url2, $cmdPayload, $topic, $suppressError);
                if ($resp2 && isset($resp2[0]['code']) && (int)$resp2[0]['code'] === 0) {
                    return $resp2;
                }
            }
        }

        return null;
    }

    private function apiGetAbilityCached(): array
    {
        $attrName = 'AbilityCache';
        $raw = @$this->ReadAttributeString($attrName);
        $now = time();

        if (is_string($raw) && $raw !== '') {
            $obj = @json_decode($raw, true);
            if (is_array($obj) && isset($obj['ts']) && ($now - (int)$obj['ts'] < 300)) {
                return (array)$obj['ability'];
            }
        }

        $res = $this->apiCall([
            ["cmd"=>"GetAbility","param"=>["User"=>["userName"=>$this->ReadPropertyString("Username")]]]
        ], 'ABILITY', /*suppress*/ true);

        $ability = is_array($res) ? ($res[0]['value']['Ability'] ?? []) : [];
        $this->WriteAttributeString($attrName, json_encode(['ts'=>$now,'ability'=>$ability]));
        return $ability;
    }

    private function apiVersionGet(string $domain): ?string {
        $raw = $this->ReadAttributeString('ApiVersionCache');
        $map = $raw ? @json_decode($raw, true) : [];
        $now = time();
        if (!isset($map[$domain])) return null;
        $age = $now - (int)$map[$domain]['ts'];
        if ($age > 86400) return null; // 24h
        return ($map[$domain]['ver'] ?? null);
    }
    private function apiVersionSet(string $domain, string $ver): void {
        $raw = $this->ReadAttributeString('ApiVersionCache');
        $map = $raw ? @json_decode($raw, true) : [];
        $map[$domain] = ['ver'=>$ver, 'ts'=>time()];
        $this->WriteAttributeString('ApiVersionCache', json_encode($map));
    }

    private function apiProbe(string $domain, string $cmdV20, string $cmdLegacy, int $action): string {
        // 1) Cache?
        $cached = $this->apiVersionGet($domain);
        if ($cached === 'v20' || $cached === 'legacy') return $cached;

        // 2) Probiere V20
        $rV20 = $this->apiCall([[ 'cmd'=>$cmdV20, 'action'=>$action, 'param'=>['channel'=>0] ]], strtoupper($domain).'/PROBE', /*suppress*/ true);
        $okV20 = is_array($rV20) && (($rV20[0]['code'] ?? -1) === 0);
        if ($okV20) { $this->apiVersionSet($domain, 'v20'); return 'v20'; }

        // 3) Probiere Legacy
        $rLeg = $this->apiCall([[ 'cmd'=>$cmdLegacy, 'action'=>$action, 'param'=>['channel'=>0] ]], strtoupper($domain).'/PROBE', /*suppress*/ true);
        $okLeg = is_array($rLeg) && (($rLeg[0]['code'] ?? -1) === 0);
        
        if ($okLeg) {
        $this->apiVersionSet($domain, 'legacy');
        return 'legacy';
        }

        $this->apiVersionSet($domain, 'unsupported');
        return 'unsupported';
    }

    // ---------------------------
    // Spotlight (White LED)
    // ---------------------------

    private function whiteLedGet(): ?array 
    {
    $ver = $this->apiProbe('spot', 'GetWhiteLed', 'GetWhiteLed', 0); // Cmd identisch
    $res = $this->apiCall([[ 'cmd'=>'GetWhiteLed', 'action'=>0, 'param'=>['channel'=>0] ]], 'SPOT');
    return (is_array($res) && (($res[0]['code'] ?? -1) === 0)) ? $res : null;
    }
    
    private function whiteLedSet(array $payload): bool 
    {
        $ver = $this->apiProbe('spot', 'SetWhiteLed', 'SetWhiteLed', 0); // Cmd identisch
        $res = $this->apiCall([[ 'cmd'=>'SetWhiteLed', 'action'=>0, 'param'=>$payload ]], 'SPOT-SET');
        return (is_array($res) && (($res[0]['code'] ?? -1) === 0));
    }

    private function SendLedRequest(array $ledParams): bool
    {
       $params = ["WhiteLed" => array_merge($ledParams, ["channel" => 0])];
        $res = $this->whiteLedSet($params);
        return (bool)$res;
    }
    private function SetWhiteLed(bool $state): bool   { return $this->SendLedRequest(['state' => $state ? 1 : 0]); }
    private function SetMode(int $mode): bool         { return $this->SendLedRequest(['mode'  => $mode]); }
    private function SetBrightness(int $b): bool      { return $this->SendLedRequest(['bright'=> $b]); }

    private function UpdateWhiteLedStatus(): void
    {
        $resp = $this->whiteLedGet();
        if (!is_array($resp) || !isset($resp[0]['value']['WhiteLed'])) {
            $this->dbg('SPOTLIGHT', 'Ungültige Antwort', $resp ?? null);
            return;
        }
        $wl = $resp[0]['value']['WhiteLed'];
        $state = [
            'state'  => isset($wl['state'])  ? (int)$wl['state']  : null,
            'mode'   => isset($wl['mode'])   ? (int)$wl['mode']   : null,
            'bright' => isset($wl['bright']) ? (int)$wl['bright'] : null,
        ];
        $this->ApplyWhiteLedStateToVars($state);
    }

    private function ApplyWhiteLedStateToVars(array $led): void
    {
        $map = [
            'WhiteLed' => array_key_exists('state',  $led) ? (bool)$led['state']   : null,
            'Mode'     => array_key_exists('mode',   $led) ? (int)$led['mode']     : null,
            'Bright'   => array_key_exists('bright', $led) ? (int)$led['bright']   : null,
        ];

        foreach ($map as $ident => $newVal) {
            if ($newVal === null) continue;
            $id = @$this->GetIDForIdent($ident);
            if ($id === false) continue;

            $oldVal = GetValue($id);
            if ($oldVal !== $newVal) {
                $this->SetValue($ident, $newVal);
                $this->dbg('SPOTLIGHT', 'Var geändert', ['ident' => $ident, 'old' => $oldVal, 'new' => $newVal]);
            }
        }
    }

    // ---------------------------
    // E-Mail (V20 / Legacy)
    // ---------------------------

    private function emailGet(): ?array 
    {
    $ver = $this->apiProbe('email', 'GetEmailV20', 'GetEmail', 0);
    $cmd = ($ver === 'v20') ? 'GetEmailV20' : 'GetEmail';
    $res = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>0, 'param'=>['channel'=>0] ]], 'EMAIL');
    return (is_array($res) && (($res[0]['code'] ?? -1) === 0)) ? $res : null;
    }
    
    private function emailSet(array $payload): bool 
    {
        $ver = $this->apiProbe('email', 'SetEmailV20', 'SetEmail', 0);
        $cmd = ($ver === 'v20') ? 'SetEmailV20' : 'SetEmail';
        $res = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>0, 'param'=>$payload ]], 'EMAIL-SET');
        return (is_array($res) && (($res[0]['code'] ?? -1) === 0));
    }

    private function IntervalSecondsToString(int $sec): ?string
    {
        switch ($sec) {
            case 30: return "30 Seconds";
            case 60: return "1 Minute";
            case 300: return "5 Minutes";
            case 600: return "10 Minutes";
            case 1800: return "30 Minutes";
        }
        return null;
    }
    private function IntervalStringToSeconds(string $s): ?int
    {
        $s = trim($s);
        $map = [ "30 Seconds" => 30, "1 Minute" => 60, "5 Minutes" => 300, "10 Minutes" => 600, "30 Minutes" => 1800 ];
        return $map[$s] ?? null;
    }

    private function GetEmailState(): ?array
    {
        $res = $this->emailGet();
        if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) return null;

        $e = $res[0]['value']['Email'] ?? $res[0]['initial']['Email'] ?? null;
        if (!is_array($e)) return null;

        $enabled = null;
        if (array_key_exists('enable', $e)) {
            $enabled = ((int)$e['enable'] === 1);
        } elseif (isset($e['schedule']['enable'])) {
            $enabled = ((int)$e['schedule']['enable'] === 1);
        }

        $intervalSec = null;
        if (isset($e['interval'])) {
            $map = ['30 Seconds'=>30,'1 Minute'=>60,'5 Minutes'=>300,'10 Minutes'=>600,'30 Minutes'=>1800];
            $intervalSec = $map[(string)$e['interval']] ?? null;
        } elseif (isset($e['intervalSec'])) {
            $intervalSec = (int)$e['intervalSec'];
        }

        $contentMode = null;
        if (isset($e['textType']) || isset($e['attachmentType'])) {
            $text = (int)($e['textType'] ?? 1);
            $att  = (int)($e['attachmentType'] ?? 0);
            if ($text === 1 && $att === 0)      $contentMode = 0;
            elseif ($text === 0 && $att === 1)  $contentMode = 1;
            elseif ($text === 1 && $att === 1)  $contentMode = 2;
            elseif ($text === 1 && $att === 2)  $contentMode = 3;
            else                                $contentMode = 0;
        } elseif (isset($e['attachment'])) {
            $contentMode = [
                '0'=>0, 'no'=>0, 'onlyPicture'=>1, 'picture'=>2, 'video'=>3
            ][(string)$e['attachment']] ?? 0;
        }

        return [
            'enabled'     => $enabled,
            'intervalSec' => $intervalSec,
            'contentMode' => $contentMode,
            'raw'         => $e
        ];
    }

    private function EmailApply(?bool $enable = null, ?int $intervalSec = null, ?int $contentMode = null): bool
    {
        if ($enable === null && $intervalSec === null && $contentMode === null) {
            if (method_exists($this, 'UpdateEmailStatus')) {
                $this->UpdateEmailStatus();
            }
            return true;
        }

        $okAll   = true;
        $touched = false;

        if ($enable !== null) {
            $touched = true;
            $ok = $this->emailSet(['Email'=>['channel'=>0, 'enable'=>($enable?1:0)]]);
            if (!$ok) {
                $ok = $this->emailSet(['Email'=>['channel'=>0, 'schedule'=>['enable'=>($enable?1:0)]]]);
            }
            $okAll = $okAll && $ok;
        }

        if ($intervalSec !== null) {
            $touched = true;
            $map = [30=>'30 Seconds', 60=>'1 Minute', 300=>'5 Minutes', 600=>'10 Minutes', 1800=>'30 Minutes'];
            $str = $map[(int)$intervalSec] ?? null;
            $ok  = $str ? $this->emailSet(['Email'=>['channel'=>0, 'interval'=>$str]]) : false;
            $okAll = $okAll && $ok;
        }

        if ($contentMode !== null) {
            $touched = true;
            $m = (int)$contentMode;

            $payloadV20 = ['Email'=>[
                'channel'        => 0,
                'textType'       => in_array($m, [0,2,3], true) ? 1 : 0,
                'attachmentType' => ($m === 1 ? 1 : ($m === 2 ? 1 : ($m === 3 ? 2 : 0))),
            ]];
            $ok = $this->emailSet($payloadV20);

            if (!$ok) {
                $payloadLegacy = ['Email'=>[
                    'channel'    => 0,
                    'attachment' => match ($m) { 1=>'onlyPicture', 2=>'picture', 3=>'video', default=>'0' },
                ]];
                $ok = $this->emailSet($payloadLegacy);
            }
            $okAll = $okAll && $ok;
        }

        if (!$touched) return true;

        if (method_exists($this, 'UpdateEmailStatus')) {
            $this->UpdateEmailStatus();
        }
        return $okAll;
    }

    public function UpdateEmailStatus(): void
    {
        $st = $this->GetEmailState();
        if (!is_array($st)) return;

        $id = @$this->GetIDForIdent('EmailNotify');
        if ($id !== false && $st['enabled'] !== null && (bool)GetValue($id) !== (bool)$st['enabled']) {
            $this->SetValue('EmailNotify', (bool)$st['enabled']);
        }

        $id = @$this->GetIDForIdent('EmailInterval');
        if ($id !== false && $st['intervalSec'] !== null && (int)GetValue($id) !== (int)$st['intervalSec']) {
            $this->SetValue('EmailInterval', (int)$st['intervalSec']);
        }

        $id = @$this->GetIDForIdent('EmailContent');
        if ($id !== false && $st['contentMode'] !== null && (int)GetValue($id) !== (int)$st['contentMode']) {
            $this->SetValue('EmailContent', (int)$st['contentMode']);
        }
    }

    // ---------------------------
    // PTZ / Zoom
    // ---------------------------

    private function CreateOrUpdatePTZHtml(bool $reloadPresets = false): void
    {
        if (!@$this->GetIDForIdent("PTZ_HTML")) {
            $this->RegisterVariableString("PTZ_HTML", "PTZ", "~HTMLBox", 8);
        }
        $hook = $this->ReadAttributeString("CurrentHook");
       
        $presets = [];
        if (!$reloadPresets) {
            $cached = $this->ReadAttributeString("PtzPresetsCache");
            if (is_string($cached) && $cached !== "") {
                $tmp = @json_decode($cached, true);
                if (is_array($tmp)) $presets = $tmp;
            }
        }
        if (empty($presets)) {
            $presets = $this->getPresetList(); 
            $this->WriteAttributeString("PtzPresetsCache", json_encode($presets, JSON_UNESCAPED_UNICODE));
        }

        $zInfo = $this->getZoomInfo(); 
        $zMin = is_array($zInfo) ? ($zInfo['min'] ?? 0) : 0;
        $zMax = is_array($zInfo) ? ($zInfo['max'] ?? 27) : 27;
        $zPos = is_array($zInfo) ? ($zInfo['pos'] ?? $zMin) : $zMin;

        // ---- UI rendern (wie zuvor) ----
        $rows = '';
        if (!empty($presets)) {
            foreach ($presets as $p) {
                $pid   = (int)$p['id'];
                $title = htmlspecialchars((string)$p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $rows .= '<div class="preset-row" data-preset="'.$pid.'">'.
                        '<button class="preset" data-preset="'.$pid.'" title="'.$title.'">'.$title.'</button>'.
                        '<div class="icons">'.
                        '<button class="icon rename" data-preset="'.$pid.'" title="Umbenennen" aria-label="Umbenennen">✎</button>'.
                        '<button class="icon del" data-preset="'.$pid.'" title="Löschen" aria-label="Löschen">🗑</button>'.
                        '</div>'.
                        '</div>';
            }
        } else {
            $rows = '<div class="no-presets">Keine Presets gefunden.</div>';
        }

        $btn = 42; $gap = 6;
        $html = <<<HTML
    <div id="ptz-wrap" style="font-family:system-ui,Segoe UI,Roboto,Arial; overflow:hidden;">
    <style>
    #ptz-wrap{ --btn: {$btn}px; --gap: {$gap}px; --fs: 16px; --radius: 10px; max-width: 520px; margin:0 auto; user-select:none; }
    #ptz-wrap .grid{ display:grid; grid-template-columns:repeat(3, var(--btn)); grid-template-rows:repeat(3, var(--btn)); gap:var(--gap); justify-content:center; align-items:center; margin-bottom:10px; }
    #ptz-wrap button{ height: var(--btn); border:1px solid #cfcfcf; border-radius:var(--radius); background:#f8f8f8; font-size:var(--fs); line-height:1; cursor:pointer; box-shadow:0 1px 2px rgba(0,0,0,.06); box-sizing:border-box; padding:6px 10px; }
    #ptz-wrap .dir{ width:var(--btn); padding:0; }
    #ptz-wrap button:hover{ filter:brightness(.98); }
    #ptz-wrap button:active{ transform:translateY(1px); }
    #ptz-wrap .up{grid-column:2;grid-row:1;} .left{grid-column:1;grid-row:2;} #ptz-wrap .right{grid-column:3;grid-row:2;} .down{grid-column:2;grid-row:3;}
    #ptz-wrap .section-title{ font-weight:600; margin:10px 0 6px; }
    #ptz-wrap .presets{ display:block; }
    #ptz-wrap .preset-row{ display:flex; align-items:center; gap:8px; margin-bottom:var(--gap); }
    #ptz-wrap .preset{ flex:1; height:auto; min-height:36px; padding:8px 12px; text-align:left; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    #ptz-wrap .icons { display:flex; gap:6px; }
    #ptz-wrap .icon{ width:36px; height:36px; display:inline-flex; align-items:center; justify-content:center; padding:0; font-size:18px; }
    #ptz-wrap .no-presets{ opacity:.7; padding:4px 0; }
    #ptz-wrap .new{ margin-top:10px; display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
    #ptz-wrap .new input[type="text"]{ flex:1; min-width:160px; height:34px; padding:4px 8px; border:1px solid #cfcfcf; border-radius:8px; }
    #ptz-wrap .new button{ height:36px; padding:6px 10px; }
    #ptz-wrap .hint{ font-size:14px; opacity:.75; }
    </style>
    <div class="grid">
    <button data-dir="up" class="dir up" title="Hoch" aria-label="Hoch">▲</button>
    <button data-dir="left" class="dir left" title="Links" aria-label="Links">◀</button>
    <button data-dir="right" class="dir right" title="Rechts" aria-label="Rechts">▶</button>
    <button data-dir="down" class="dir down" title="Runter" aria-label="Runter">▼</button>
    </div>
    <div class="section-title">Zoom</div>
    <div class="zoomline" style="margin:6px 0 12px; text-align:center;">
    <input type="range" id="ptz-zoom" min="{$zMin}" max="{$zMax}" step="1" value="{$zPos}" style="width:220px;">
    </div>
    <div class="section-title">Presets</div>
    <div class="presets"> {$rows} </div>
    <div class="section-title">Neues Preset</div>
    <div class="new">
    <input type="text" id="ptz-new-name" maxlength="32" placeholder="Name eingeben …"/>
    <button id="ptz-new-save" title="Aktuelle Position als neues Preset speichern">Speichern</button>
    </div>
    <script>
    (function(){
    var base = "{$hook}";
    var wrap = document.getElementById("ptz-wrap");
    var msg  = document.getElementById("ptz-msg");
    var nameIn   = document.getElementById("ptz-new-name");

    function show(text, ok){
        if (!msg) return;
        msg.className = "status " + (ok ? "ok" : "err");
        msg.textContent = text;
    }
    function call(op, extra){
        var qs = new URLSearchParams(extra || {});
        qs.set("ptz", op);
        var url = base + "?" + qs.toString();
        return fetch(url, { method: "GET", credentials: "same-origin", cache: "no-store" })
        .then(function(r){ return r.text().catch(function(){ return ""; }); })
        .then(function(t){
            var body = (t || "").trim().toUpperCase();
            var ok = (body === "OK");
            if (ok) { show("OK", true); return true; }
            if ((t || "").trim() === "") { show("Gesendet", true); return true; }
            show("Fehler: " + (t || ""), false); return false;
        })
        .catch(function(){ show("Gesendet", true); return true; });
    }
    function calcNextId(){
        var rows = wrap.querySelectorAll(".preset-row[data-preset]");
        var max = -1;
        rows.forEach(function(el){
        var v = parseInt(el.getAttribute("data-preset") || "-1", 10);
        if (!isNaN(v) && v > max) max = v;
        });
        return max + 1;
    }

    var zoomEl = document.getElementById("ptz-zoom");
    if (zoomEl) {
        zoomEl.addEventListener("change", function(){
        var target = parseInt(zoomEl.value, 10);
        if (isNaN(target)) return;
        call("zoompos", { pos: target });
        });
    }

    wrap.addEventListener("click", function(ev){
        var btn = ev.target.closest("button");
        if (!btn) return;

        if (btn.hasAttribute("data-dir")) {
        call(btn.getAttribute("data-dir")); return;
        }
        if (btn.classList.contains("preset") && btn.hasAttribute("data-preset")) {
        call("preset:" + btn.getAttribute("data-preset")); return;
        }
        if (btn.classList.contains("rename") && btn.hasAttribute("data-preset")) {
        var id = parseInt(btn.getAttribute("data-preset") || "0", 10);
        var cur = (btn.parentElement && btn.parentElement.previousElementSibling) ? btn.parentElement.previousElementSibling.textContent.trim() : "";
        var neu = window.prompt("Neuer Name für Preset " + id + ":", cur);
        if (neu && neu.trim() !== "") { call("rename", { id:id, name:neu.trim() }); }
        return;
        }
        if (btn.classList.contains("del") && btn.hasAttribute("data-preset")) {
        var idd = parseInt(btn.getAttribute("data-preset") || "0", 10);
        if (window.confirm("Preset " + idd + " löschen?")) { call("delete", { id: idd }); }
        return;
        }
        if (btn.id === "ptz-new-save") {
        var nm = (nameIn.value || "").trim();
        if (!nm) { show("Bitte einen Namen eingeben.", false); return; }
        var nid = calcNextId();
        call("save", { id: nid, name: nm }).then(function(ok){ if (ok) { nameIn.value = ""; } });
        return;
        }
    });
    })();
    </script>
    HTML;

        $this->setHtmlIfChanged("PTZ_HTML", $html);
    }

    private function setHtmlIfChanged(string $ident, string $html): void
    {
        $id  = @$this->GetIDForIdent($ident);
        $old = ($id !== false) ? GetValue($id) : null;
        if (!is_string($old) || $old !== $html) {
            $this->SetValue($ident, $html);
        }
    }

    private function HandlePtzCommand(string $cmd): bool
    {
        $stepParam = isset($_REQUEST['step']) ? max(1, (int)$_REQUEST['step']) : 1;
        $idParam   = $_REQUEST['id']   ?? null;
        $nameParam = $_REQUEST['name'] ?? null;
        $id   = is_null($idParam)   ? null : (int)$idParam;
        $name = is_null($nameParam) ? null : (string)$nameParam;

        if (strpos($cmd, 'preset:') === 0) {
            $pid = (int)substr($cmd, 7);
            if ($pid >= 0) return $this->ptzGotoPreset($pid);
            $this->dbg("PTZ", "Ungueltige Preset-ID", $cmd);
            return false;
        }

        switch (strtolower($cmd)) {
            case 'save':
                if ($id === null || $id < 0) { $this->dbg("PTZ","id fehlt/ungueltig"); return false; }
                if (is_string($name)) {
                    $name = trim($name);
                    if ($name === '') $name = null;
                    if ($name !== null) {
                        $name = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', $name);
                        $name = mb_substr($name, 0, 32, 'UTF-8');
                    }
                } else { $name = null; }
                $ok = $this->PTZ_SavePreset($id, $name);
                $this->dbg("PTZ", "SAVE", ['id'=>$id, 'name'=>$name, 'ok'=>$ok]);
                return $ok;

            case 'rename':
                if ($id === null || $id < 0 || !is_string($name) || trim($name) === '') { $this->dbg("PTZ","id/name fehlen"); return false; }
                $name = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', trim($name));
                $name = mb_substr($name, 0, 32, 'UTF-8');
                $ok = $this->PTZ_RenamePreset($id, $name);
                $this->dbg("PTZ", "RENAME", ['id'=>$id, 'name'=>$name, 'ok'=>$ok]);
                return $ok;

            case 'delete':
                if ($id === null || $id < 0) { $this->dbg("PTZ","id fehlt"); return false; }
                $ok = $this->PTZ_DeletePreset($id);
                $this->dbg("PTZ", "DELETE", ['id'=>$id, 'ok'=>$ok]);
                return $ok;

            case 'zoomin':  return $this->ptzZoom('in',  $stepParam);
            case 'zoomout': return $this->ptzZoom('out', $stepParam);

            case 'zoompos':
                if (!isset($_REQUEST['pos'])) { $this->dbg("PTZ","pos fehlt"); return false; }
                $pos = (int)$_REQUEST['pos'];
                $info = $this->getZoomInfo();
                if (is_array($info)) $pos = max($info['min'], min($info['max'], $pos));
                $ok = $this->setZoomPos($pos);
                $this->dbg("PTZ", "ZOOMPOS", ['pos'=>$pos, 'ok'=>$ok]);
                return $ok;
        }

        $map = [ 'left' => 'Left', 'right' => 'Right', 'up' => 'Up', 'down' => 'Down' ];
        $k = strtolower($cmd);
        if (!isset($map[$k])) {
            $this->dbg("PTZ", "Unbekanntes Kommando", $cmd);
            return false;
        }
        return $this->ptzCtrl($map[$k]);
    }

    private function getPtzStyle(): string
    {
        $s = $this->ReadAttributeString("PtzStyle");
        return ($s === "flat" || $s === "nested") ? $s : "";
    }
    private function setPtzStyle(string $s): void
    {
        if ($s === "flat" || $s === "nested") {
            $this->WriteAttributeString("PtzStyle", $s);
            $this->dbg("PTZ", "PtzStyle gesetzt", $s);
        }
    }

    private function postCmdDual(string $cmd, array $body, ?string $nestedKey=null, bool $suppress=false): ?array
    {
        $nestedKey = $nestedKey ?: $cmd;
        $known = $this->getPtzStyle();
        $order = $known ? [$known, ($known === 'flat' ? 'nested' : 'flat')] : ['flat','nested'];

        foreach ($order as $mode) {
            $payload = [[ 'cmd' => $cmd, 'param' => ($mode === 'flat') ? $body : [$nestedKey => $body] ]];
            $resp = $this->apiCall($payload, 'PTZ', /*suppress*/ true);
            if (is_array($resp) && (($resp[0]['code'] ?? -1) === 0)) {
                if ($known !== $mode) $this->setPtzStyle($mode);
                return $resp;
            }
        }
        if (!$suppress) {
            $this->dbg("PTZ", "postCmdDual FAIL", ['cmd'=>$cmd, 'body'=>$body]);
            $this->LogMessage("Reolink/PTZ: postCmdDual FAIL for {$cmd}", KL_ERROR);
        }
        return null;
    }

    private function ptzCtrl(string $op, array $extra = [], int $pulseMs = 250): bool
    {
        $param = ['channel' => 0, 'op' => $op] + $extra;
        $isMove = in_array($op, ['Left','Right','Up','Down'], true);
        if ($isMove && !isset($param['speed'])) {
            $param['speed'] = 5;
        }

        $ok = is_array($this->postCmdDual('PtzCtrl', $param, 'PtzCtrl', /*suppress*/ false));
        if (!$ok) return false;

        if ($isMove) {
            IPS_Sleep($pulseMs);
            $this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'Stop'], 'PtzCtrl', /*suppress*/ true);
        }
        return true;
    }

    private function ptzGotoPreset(int $id): bool
    {
        $ok = is_array($this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'ToPos',    'id'=>$id], 'PtzCtrl', /*suppress*/ true));
        if ($ok) return true;
        $ok = is_array($this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'ToPreset', 'id'=>$id], 'PtzCtrl', /*suppress*/ true));
        if ($ok) return true;
        $this->dbg('PTZ', "Preset anfahren fehlgeschlagen", $id);
        return false;
    }

    private function collectPresetsRecursive($node, array &$out, array &$seen): void
    {
        if (!is_array($node) || empty($node)) return;

        $first = reset($node);
        if (is_array($first) && (isset($first['id']) || isset($first['Id']))) {
            foreach ($node as $p) {
                if (!is_array($p)) continue;
                $rawId = $p['id'] ?? $p['Id'] ?? null;
                if (!is_numeric($rawId)) continue;
                $id = (int)$rawId;
                if (isset($seen[$id])) continue;

                $en = $p['enable'] ?? $p['Enable'] ?? null;
                if ($en !== null && (int)$en === 0) continue;

                $name = $p['name'] ?? $p['Name'] ?? $p['sName'] ?? $p['label'] ?? $p['presetName'] ?? '';
                $trim = trim((string)$name);

                $isGeneric = ($trim !== '') && (preg_match('/^(pos|preset|position)\s*0*\d+$/i', $trim) === 1);
                $flag  = $p['exist'] ?? $p['bExist'] ?? $p['bexistPos'] ?? $p['enable'] ?? $p['enabled'] ?? $p['set'] ?? $p['bSet'] ?? null;
                $isSet = ($flag === 1 || $flag === '1' || $flag === true);
                $posArr = $p['pos'] ?? $p['position'] ?? $p['ptzpos'] ?? $p['ptz'] ?? null;
                $hasPos = false;
                if (is_array($posArr)) {
                    foreach ($posArr as $v) {
                        if (is_numeric($v) && (float)$v != 0.0) { $hasPos = true; break; }
                    }
                }
                if (($trim === '' || $isGeneric) && !$isSet && !$hasPos) continue;
                if ($trim === '') $name = "Preset ".$id;

                $out[] = ['id'=>$id, 'name'=>$name];
                $seen[$id] = true;
            }
        }

        foreach ($node as $v) {
            if (is_array($v)) $this->collectPresetsRecursive($v, $out, $seen);
        }
    }

    private function getPresetList(): array
    {
        $res = $this->postCmdDual('GetPtzPreset', ['channel'=>0], 'GetPtzPreset', /*suppress*/ true);
        $list = []; $seen = [];
        if (is_array($res)) {
            $v = $res[0]['value'] ?? [];
            $ps = $v['PtzPreset']['preset'] ?? $v['PtzPreset']['table'] ?? $v['preset'] ?? $v['table'] ?? null;
            if (is_array($ps)) {
                $this->collectPresetsRecursive($ps, $list, $seen);
            } else {
                $this->collectPresetsRecursive($v, $list, $seen);
            }
            if (empty($list)) {
                $this->collectPresetsRecursive($res, $list, $seen);
            }
            if (empty($list)) {
                $this->dbg('PTZ', 'Keine Presets erkannt', $res);
            }
        } else {
            $this->dbg('PTZ', 'Kein gueltiges Array-Response');
        }
        usort($list, fn($a,$b) => $a['id'] <=> $b['id']);
        return $list;
    }

    private function getZoomInfo(): ?array
    {
        $res = $this->postCmdDual('GetZoomFocus', ['channel'=>0], 'ZoomFocus', /*suppress*/ false);
        if (!is_array($res) || !isset($res[0]['value'])) return null;
        $val  = $res[0]['value'];
        $zf   = $val['ZoomFocus'] ?? $val['zoomFocus'] ?? null;
        $zoom = is_array($zf) ? ($zf['zoom'] ?? $zf['Zoom'] ?? null) : null;
        if (!is_array($zoom)) return null;
        $pos = (int)($zoom['pos'] ?? $zoom['Pos'] ?? 0);
        $min = (int)($zoom['min'] ?? $zoom['Min'] ?? 0);
        $max = (int)($zoom['max'] ?? $zoom['Max'] ?? 10);
        return ['pos'=>$pos, 'min'=>$min, 'max'=>$max];
    }

    private function setZoomPos(int $pos): bool
    {
        $info = $this->getZoomInfo() ?: ['min'=>0, 'max'=>10];
        $min = (int)$info['min']; $max = (int)$info['max'];
        $p = max($min, min($max, $pos));
        $sendPos = $p;
        if ($max > 10) {
            $sendPos = (int)round(($p - $min) * 10 / max(1, $max - $min));
        }
        $res = $this->postCmdDual('StartZoomFocus', ['channel'=>0, 'op'=>'ZoomPos', 'pos'=>$sendPos], 'ZoomFocus', /*suppress*/ true);
        return is_array($res) && (($res[0]['code'] ?? -1) === 0);
    }

    private function ptzZoom(string $dir, int $step = 1): bool
    {
        $step = max(1, min(27, $step));
        $pulseMs = 160; $gapMs = 60;
        $ops = ($dir === 'in') ? ['ZoomIn','ZoomTele','ZoomAdd'] : ['ZoomOut','ZoomWide','ZoomDec'];
        $okAny = false;
        for ($i = 0; $i < $step; $i++) {
            $ok = false;
            foreach ($ops as $op) {
                $res = $this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>$op], 'PtzCtrl', /*suppress*/ true);
                if (is_array($res) && (($res[0]['code'] ?? -1) === 0)) {
                    $ok = $okAny = true;
                    IPS_Sleep($pulseMs);
                    $this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'Stop'], 'PtzCtrl', /*suppress*/ true);
                    break;
                }
            }
            if (!$ok) break;
            if ($i < $step-1) IPS_Sleep($gapMs);
        }
        return $okAny;
    }

    public function PTZ_SavePreset(int $id, ?string $name=null): bool
    {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzSetPreset($id);
        if ($ok && $name) { $this->ptzRenamePreset($id, $name); }
        if ($ok) {
            $this->WriteAttributeString("PtzPresetsCache", "");   
            $this->CreateOrUpdatePTZHtml(true);                   
        }
        return $ok;
    }

    public function PTZ_RenamePreset(int $id, string $name): bool
    {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzRenamePreset($id, $name);
        if ($ok) {
            $this->WriteAttributeString("PtzPresetsCache", "");
            $this->CreateOrUpdatePTZHtml(true);
        }
        return $ok;
    }

    public function PTZ_DeletePreset(int $id): bool
    {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzClearPreset($id);
        if ($ok) {
            $this->WriteAttributeString("PtzPresetsCache", "");
            $this->CreateOrUpdatePTZHtml(true);
        }
        return $ok;
    }

    // ---------------------------
    // PTZ Presets: Set/Rename/Clear
    // ---------------------------

    private function ptzSetPreset(int $id, ?string $nameForCreate=null): bool {
        $entry = ['id'=>$id, 'enable'=>1];
        if ($nameForCreate !== null && $nameForCreate !== '') {
            $n = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', $nameForCreate);
            $entry['name'] = mb_substr($n, 0, 32, 'UTF-8');
        }
        $ok = is_array($this->postCmdDual(
            'SetPtzPreset',
            ['channel'=>0, 'table'=>[ $entry ]],
            'PtzPreset', 
            true
        ));
        
        if (!$ok) {
            $flat = ['channel'=>0, 'id'=>$id, 'enable'=>1] + (isset($entry['name'])?['name'=>$entry['name']]:[]);
            $ok = is_array($this->postCmdDual(
                'SetPtzPreset',
                $flat,
                'PtzPreset',
                true
            ));
        }
        if (!$ok) $this->dbg('PTZ/SetPtzPreset', 'Fehlgeschlagen', $entry);
        return (bool)$ok;
    }

    private function ptzRenamePreset(int $id, string $name): bool {
        $name = trim($name);
        if ($name === '') return false;
        $name = preg_replace('/[^\p{L}\p{N}\s\-\_\.]/u', '', $name);
        $name = mb_substr($name, 0, 32, 'UTF-8');

        $ok = is_array($this->postCmdDual(
            'SetPtzPreset',
            ['channel'=>0, 'table'=>[ ['id'=>$id, 'name'=>$name] ]],
            'PtzPreset', 
            true
        ));
        if (!$ok) {
            $ok = is_array($this->postCmdDual(
                'SetPtzPreset',
                ['channel'=>0, 'id'=>$id, 'name'=>$name],
                'PtzPreset', 
                true
            ));
        }
    
        if (!$ok) {
            $ok = is_array($this->postCmdDual('PtzPreset', ['channel'=>0,'id'=>$id,'name'=>$name,'cmd'=>'SetName'], 'PtzPreset', true))
            ?: is_array($this->postCmdDual('PtzCtrl',   ['channel'=>0,'op'=>'SetPresetName','id'=>$id,'name'=>$name], 'PtzCtrl', true));
        }
        if (!$ok) $this->dbg('PTZ/Rename', 'Fehlgeschlagen', ['id'=>$id,'name'=>$name]);
        return (bool)$ok;
    }

    
    private function ptzClearPreset(int $id): bool {
        $ok = is_array($this->postCmdDual(
            'SetPtzPreset',
            ['channel'=>0, 'table'=>[ ['id'=>$id, 'enable'=>0, 'name'=>''] ]],
            'PtzPreset',   
            true
        ));
        if (!$ok) {
            $ok = is_array($this->postCmdDual(
                'SetPtzPreset',
                ['channel'=>0, 'id'=>$id, 'enable'=>0, 'name'=>''],
                'PtzPreset', 
                true
            ));
        }
        if (!$ok) $this->dbg('PTZ/Clear', 'enable=0 fehlgeschlagen', ['id'=>$id]);
        return (bool)$ok;
    }

    // ---------------------------
    // PTZ GotoPresets
    // ---------------------------

    public function PTZ_ListPresets(): array
    {
        return $this->getPresetList();
    }

    public function PTZ_GotoPresetByName(string $name, bool $partial = true): bool
    {
        if (!$this->apiEnsureToken()) {
            $this->SendDebug(__FUNCTION__, 'Kein Token', 0);
            return false;
        }

        $needle = mb_strtolower(trim($name));
        if ($needle === '') {
            $this->SendDebug(__FUNCTION__, 'Leerer Name', 0);
            return false;
        }

        $presets = $this->getPresetList();
        if (!is_array($presets) || !$presets) {
            $this->SendDebug(__FUNCTION__, 'Keine Presets gefunden', 0);
            return false;
        }

        // 1) Exakter (case-insensitiver) Treffer
        foreach ($presets as $p) {
            $pname = mb_strtolower((string)$p['name']);
            if ($pname === $needle) {
                $this->SendDebug(__FUNCTION__, 'Exakter Treffer: '.$p['name'].' (#'.$p['id'].')', 0);
                return $this->ptzGotoPreset((int)$p['id']);
            }
        }

        // 2) Optional: Teiltreffer
        if ($partial) {
            foreach ($presets as $p) {
                $pname = mb_strtolower((string)$p['name']);
                if (mb_stripos($pname, $needle) !== false) {
                    $this->SendDebug(__FUNCTION__, 'Teiltreffer: '.$p['name'].' (#'.$p['id'].')', 0);
                    return $this->ptzGotoPreset((int)$p['id']);
                }
            }
        }

        $this->SendDebug(__FUNCTION__, 'Kein Preset mit Name "'.$name.'" gefunden', 0);
        return false;
    }

    public function PTZ_GotoPreset(int $id): bool
    {
        if (!$this->apiEnsureToken()) return false;
        return $this->ptzGotoPreset($id);
    }


    // ---------------------------
    // FTP EIN/AUS
    // ---------------------------

    private function ftpGet(): ?array
    {
        $ver = $this->apiProbe('ftp', 'GetFtpV20', 'GetFtp', 0);
        if ($ver === 'unsupported') return null;

        $cmd = ($ver === 'v20') ? 'GetFtpV20' : 'GetFtp';

        $res = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>0, 'param'=>['channel'=>0] ]], 'FTP', /*suppress*/ true);
        if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) {
            $res = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>1, 'param'=>['channel'=>0] ]], 'FTP');
        }
        return (is_array($res) && (($res[0]['code'] ?? -1) === 0)) ? $res : null;
    }

    private function ftpSet(bool $on): bool
    {
        $ver = $this->apiProbe('ftp', 'SetFtpV20', 'SetFtp', 0);
        if ($ver === 'unsupported') return false;

        $cmd = ($ver === 'v20') ? 'SetFtpV20' : 'SetFtp';

        $p1  = [ 'Ftp' => [ 'enable' => ($on ? 1 : 0), 'channel' => 0 ] ];
        $r1  = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>0, 'param'=>$p1 ]], 'FTP-SET', /*suppress*/ true);
        $ok1 = is_array($r1) && (($r1[0]['code'] ?? -1) === 0);
        if ($ok1) return true;

        $r1b = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>1, 'param'=>$p1 ]], 'FTP-SET', /*suppress*/ true);
        $ok1b = is_array($r1b) && (($r1b[0]['code'] ?? -1) === 0);
        if ($ok1b) return true;

        $p2  = [ 'Ftp' => [ 'schedule' => ['enable' => ($on ? 1 : 0)], 'channel' => 0 ] ];
        $r2  = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>0, 'param'=>$p2 ]], 'FTP-SET', /*suppress*/ true);
        $ok2 = is_array($r2) && (($r2[0]['code'] ?? -1) === 0);
        if ($ok2) return true;

        $r2b = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>1, 'param'=>$p2 ]], 'FTP-SET');
        return is_array($r2b) && (($r2b[0]['code'] ?? -1) === 0);
    }

    private function UpdateFtpStatus(): void
    {
        $res = $this->ftpGet();
        if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) return;

        $ftp = $res[0]['value']['Ftp'] ?? $res[0]['initial']['Ftp'] ?? null;
        if (!is_array($ftp)) return;

        $enabled = null;
        if (array_key_exists('enable', $ftp)) $enabled = ((int)$ftp['enable'] === 1);
        elseif (isset($ftp['schedule']['enable'])) $enabled = ((int)$ftp['schedule']['enable'] === 1);
        if ($enabled === null) return;

        $id = @$this->GetIDForIdent('FTPEnabled');
        if ($id !== false && (bool)GetValue($id) !== $enabled) {
            $this->SetValue('FTPEnabled', (bool)$enabled);
            $this->dbg('FTP', 'Var geändert', ['old' => (bool)GetValue($id), 'new' => (bool)$enabled]);
        }
    }

    private function FtpApply(bool $on): bool
    {
        $ok = $this->ftpSet($on);
        if ($ok) $this->UpdateFtpStatus();
        else     $this->dbg('FTP', 'Setzen fehlgeschlagen');
        return $ok;
    }

    // ---------------------------
    // Sensitivity
    // ---------------------------

    private function sensitivityGet(): ?array 
    {
        $ver = $this->apiProbe('sensitivity', 'GetMdAlarm', 'GetAlarm', 1);
        $cmd = ($ver === 'v20') ? 'GetMdAlarm' : 'GetAlarm';

        $res = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>1, 'param'=>['channel'=>0] ]], 'SENS');
        if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) {
            $res = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>0, 'param'=>['channel'=>0] ]], 'SENS');
        }
        if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) return null;

        $root = $res[0];
        $node = $root['value']['MdAlarm'] ?? $root['initial']['MdAlarm'] ?? $root['value']['Alarm'] ?? $root['initial']['Alarm'] ?? null;
        if (!is_array($node)) return null;

        $sensDef  = null;
        $segments = [];
        if (!empty($node['newSens'])) {
            $sensDef  = isset($node['newSens']['sensDef']) ? (int)$node['newSens']['sensDef'] : null;
            $segments = $this->mdNormalizeSegments($node['newSens']['sens'] ?? []);
        } elseif (!empty($node['sens'])) {
            $segments = $this->mdNormalizeSegments($node['sens']);
        }

        $active = $this->mdPickActiveNow($segments, $sensDef);
        return [ 'apiVer' => ($ver === 'v20' ? 'V20' : 'LEGACY'), 'sensDef'=>$sensDef, 'segments'=>$segments, 'active'=>$active ];
    }

    private function sensitivitySet(int $levelUi): bool 
    {
        $levelUi  = max(1, min(50, $levelUi));
        $levelCam = 51 - $levelUi;

        $state = $this->sensitivityGet();
        if (!$state) return false;

        $segments = $state['segments'];
        if (empty($segments)) {
            $segments = [[ 'beginHour'=>0,'beginMin'=>0,'endHour'=>23,'endMin'=>59,'sensitivity'=>$levelCam ]];
        } else {
            foreach ($segments as &$s) { $s['sensitivity'] = $levelCam; }
            unset($s);
        }

        $isV20 = ($state['apiVer'] === 'V20');

        if ($isV20) {
            $payload = [[
                'cmd'   => 'SetMdAlarm',
                'param' => [
                    'MdAlarm' => [
                        'type'       => 'md',
                        'useNewSens' => 1,
                        'newSens'    => [
                            'sensDef' => $levelCam,
                            'sens'    => $segments
                        ],
                        'channel'    => 0
                    ]
                ]
            ]];
        } else {
            $payload = [[
                'cmd'   => 'SetAlarm',
                'param' => [
                    'Alarm' => [
                        'type'    => 'md',
                        'sens'    => $segments,
                        'channel' => 0
                    ]
                ]
            ]];
        }

        $res = $this->apiCall($payload, 'SENS-SET');
        $ok  = (is_array($res) && (($res[0]['code'] ?? -1) === 0));

        if ($ok) {
            $this->UpdateMdSensitivityStatus(); 
        }
        return $ok;
    }

    private function GetMdSensitivity(): ?array {
        return $this->sensitivityGet();
    }

    public function SetMdSensitivity(int $level): bool {
        return $this->sensitivitySet($level);
    }

    private function UpdateMdSensitivityStatus(): void
    {
        $vid = @$this->GetIDForIdent("MdSensitivity");
        if ($vid === false) return;

        $st = $this->GetMdSensitivity();
        if (!$st) return;

        $lvlCam = max(1, min(50, (int)($st['active'] ?? 0)));
        $lvlUI  = 51 - $lvlCam;

        if ((int)GetValue($vid) !== $lvlUI) {
            $this->SetValue("MdSensitivity", $lvlUI);
        }
    }

    private function mdNormalizeSegments($raw): array
    {
        $out = [];
        $push = function($a) use (&$out) {
            $out[] = [
                'beginHour'   => (int)($a['beginHour'] ?? 0),
                'beginMin'    => (int)($a['beginMin']  ?? 0),
                'endHour'     => (int)($a['endHour']   ?? 23),
                'endMin'      => (int)($a['endMin']    ?? 59),
                'sensitivity' => (int)($a['sensitivity'] ?? ($a['sens'] ?? 0))
            ];
        };
        $walk = function($node) use (&$walk, $push) {
            if (is_array($node)) {
                if (isset($node['beginHour']) || isset($node['beginMin']) || isset($node['endHour']) || isset($node['endMin'])) {
                    $push($node);
                } else {
                    foreach ($node as $v) $walk($v);
                }
            }
        };
        $walk($raw);

        $filtered = [];
        foreach ($out as $s) {
            $bh=$s['beginHour']; $bm=$s['beginMin']; $eh=$s['endHour']; $em=$s['endMin'];
            if ($bh<0||$bh>23||$eh<0||$eh>23||$bm<0||$bm>59||$em<0||$em>59) continue;
            if ($s['sensitivity'] < 1 || $s['sensitivity'] > 50) continue;
            $filtered[] = $s;
        }
        return array_values($filtered);
    }

    private function mdPickActiveNow(array $segments, ?int $sensDef): int
    {
        $now = (int)date('G')*60 + (int)date('i');
        foreach ($segments as $s) {
            $start = $s['beginHour']*60 + $s['beginMin'];
            $end   = $s['endHour']*60   + $s['endMin'];
            if ($start <= $end) {
                if ($now >= $start && $now <= $end) return (int)$s['sensitivity'];
            } else {
                if ($now >= $start || $now <= $end)  return (int)$s['sensitivity'];
            }
        }
        return (int)($sensDef ?? ($segments[0]['sensitivity'] ?? 10));
    }

    private function apiGetNode(array $resp, string $key)
    {
        $root = $resp[0] ?? [];
        $v = $root['value'][$key] ?? null;
        if ($v === null) $v = $root['initial'][$key] ?? null;
        return is_array($v) ? $v : null;
    }

    // ---------------------------
    // Sirene ein-aus
    // ---------------------------

    private function alarmGet(): ?array 
    {
    $ver = $this->apiProbe('alarm', 'GetAudioAlarmV20', 'GetAudioAlarm', 1);
    $cmd = ($ver === 'v20') ? 'GetAudioAlarmV20' : 'GetAudioAlarm';
    $res = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>1, 'param'=>['channel'=>0] ]], 'ALARM');
    return (is_array($res) && (($res[0]['code'] ?? -1) === 0)) ? $res : null;
    }
    
    private function alarmSet(array $audioNode): bool 
    {
        $ver = $this->apiProbe('alarm', 'SetAudioAlarmV20', 'SetAudioAlarm', 1);
        $cmd = ($ver === 'v20') ? 'SetAudioAlarmV20' : 'SetAudioAlarm';
        $res = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>1, 'param'=>['Audio'=>$audioNode] ]], 'ALARM-SET');
        return (is_array($res) && (($res[0]['code'] ?? -1) === 0));
    }

    public function SetSirenEnabled(bool $on): bool
    {
        $res = $this->alarmGet();
        if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) return false;
        $audio = $res[0]['value']['Audio'] ?? $res[0]['initial']['Audio'] ?? null;
        if (!is_array($audio)) return false;

        $audio['enable'] = $on ? 1 : 0;
        $ok = $this->alarmSet($audio);

        if ($ok) $this->UpdateSirenStatus();
        return $ok;
    }

    private function UpdateSirenStatus(): void
    {
        $vid = @$this->GetIDForIdent("SirenEnabled");
        if ($vid === false) return;

        $res = $this->alarmGet();
        if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) return;

        $audio = $res[0]['value']['Audio'] ?? $res[0]['initial']['Audio'] ?? null;
        if (!is_array($audio) || !array_key_exists('enable', $audio)) return;

        $enabled = ((int)$audio['enable'] === 1);
        if ((bool)GetValue($vid) !== $enabled) {
            $this->SetValue("SirenEnabled", $enabled);
        }
    }


    // ---------------------------
    // Sirene ansteuern
    // ---------------------------

    private function SirenPlayTimes(int $times): bool
    {
        $times = max(1, min(10, $times)); 
        $payload = [[
            "cmd"   => "AudioAlarmPlay",
            "param" => [
                "alarm_mode" => "times",
                "times"      => $times,
                "channel"    => 0
            ]
        ]];
        $res = $this->apiCall($payload, 'AUDIO_PLAY');
        $ok  = is_array($res) && (($res[0]['code'] ?? -1) === 0);
        if (!$ok) $this->dbg('AUDIO_PLAY', 'FAIL', $res ?? null);
        return $ok;
    }

    private function SirenManualSwitch(bool $on): bool
    {
        $payload = [[
            "cmd"   => "AudioAlarmPlay",
            "param" => [
                "alarm_mode"    => "manu",
                "manual_switch" => $on ? 1 : 0,
                "channel"       => 0
            ]
        ]];
        $res = $this->apiCall($payload, 'AUDIO_MANU');
        $ok  = is_array($res) && (($res[0]['code'] ?? -1) === 0);
        if (!$ok) $this->dbg('AUDIO_MANU', 'FAIL', $res ?? null);
        return $ok;
    }


    // ---------------------------
    // Record Status
    // ---------------------------

    private function recordGet(): ?array 
    {
    $ver = $this->apiProbe('record', 'GetRecV20', 'GetRec', 1);
    $cmd = ($ver === 'v20') ? 'GetRecV20' : 'GetRec';
    $res = $this->apiCall([[ 'cmd'=>$cmd, 'action'=>1, 'param'=>['channel'=>0] ]], 'RECORD');
    return (is_array($res) && (($res[0]['code'] ?? -1) === 0)) ? $res : null;
    }

    private function recordSet(array $payload): bool
    {
        $ver = $this->apiProbe('record', 'SetRecV20', 'SetRec', 0);
        $cmd = ($ver === 'v20') ? 'SetRecV20' : 'SetRec';

        $r0  = $this->apiCall([[ 'cmd' => $cmd, 'action' => 0, 'param' => $payload ]], 'RECORD-SET', /*suppress*/ true);
        $ok0 = is_array($r0) && (($r0[0]['code'] ?? -1) === 0);
        if ($ok0) return true;

        $r1  = $this->apiCall([[ 'cmd' => $cmd, 'action' => 1, 'param' => $payload ]], 'RECORD-SET', /*suppress*/ false);
        return is_array($r1) && (($r1[0]['code'] ?? -1) === 0);
    }

    private function UpdateRecStatus(): void
    {
        $vid = @$this->GetIDForIdent("RecEnabled");
        if ($vid === false) return;

        $res = $this->recordGet();
        if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) return;

        $rec = $res[0]['value']['Rec'] ?? $res[0]['initial']['Rec'] ?? null;
        if (!is_array($rec)) return;

        $enabled = null;
        if (array_key_exists('enable', $rec)) {
            $enabled = ((int)$rec['enable'] === 1);
        } elseif (isset($rec['schedule']['enable'])) {
            $enabled = ((int)$rec['schedule']['enable'] === 1);
        }
        if ($enabled !== null && ((bool)GetValue($vid) !== $enabled)) {
            $this->SetValue('RecEnabled', $enabled);
        }
    }

    public function SetRecEnabled(bool $on): bool
    {
        $get = $this->recordGet();
        if (!is_array($get) || (($get[0]['code'] ?? -1) !== 0)) return false;

        $rec = $get[0]['value']['Rec'] ?? $get[0]['initial']['Rec'] ?? [];
        if (!is_array($rec)) $rec = [];

        $rec['enable']  = $on ? 1 : 0;
        $rec['channel'] = 0;

        $ok = $this->recordSet(['Rec' => $rec]);

        if (!$ok) {
            $param2 = ['Rec' => ['schedule' => ['enable' => ($on ? 1 : 0)], 'channel' => 0]];
            $ok = $this->recordSet($param2);
        }

        if ($ok) {
            $this->UpdateRecStatus();
        }
        return $ok;
    }

    // ---------------------------
    // Infrared (IR)
    // ---------------------------

    public function IR_SetModeInt(int $mode): bool
    {
        if (!$this->ReadPropertyBoolean('EnableApiIR')) return false;
        if (!$this->apiEnsureToken()) return false;

        $mode = max(0, min(2, $mode));
        $map  = [0 => 'Off', 1 => 'On', 2 => 'Auto'];

        $payload = [[
            'cmd'   => 'SetIrLights',
            'action'=> 0,
            'param' => ['IrLights' => [
                'channel' => 0,
                'state'   => $map[$mode] 
            ]]
        ]];

        $res = $this->apiCall($payload, 'IR-SET');
        $ok  = is_array($res) && (($res[0]['code'] ?? -1) === 0);
        if ($ok) { $this->UpdateIrStatus(); }
        return $ok;
    }

    private function irGetMode(): ?string
    {
        if (!$this->ReadPropertyBoolean('EnableApiIR')) return null;
        if (!$this->apiEnsureToken()) return null;

        $p0  = [[ 'cmd'=>'GetIrLights', 'action'=>0, 'param'=>['channel'=>0] ]];
        $res = $this->apiCall($p0, 'IR', /*suppress*/ true);
        if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) {
            $p1  = [[ 'cmd'=>'GetIrLights', 'action'=>1, 'param'=>['channel'=>0] ]];
            $res = $this->apiCall($p1, 'IR');
            if (!is_array($res) || (($res[0]['code'] ?? -1) !== 0)) return null;
        }

        $root = $res[0] ?? [];
        $node = $root['value']['IrLights'] ?? $root['initial']['IrLights'] ?? null;
        if (!is_array($node)) return null;

        $raw = $node['state'] ?? null;
        if (is_string($raw)) {
            $s = strtolower($raw);
            if (in_array($s, ['off','on','auto'], true)) return $s;
        }
        if (is_int($raw)) {
            return [0=>'off',1=>'on',2=>'auto'][$raw] ?? null;
        }
        return null;
    }

    private function UpdateIrStatus(): void
    {
        $mode = $this->irGetMode();
        if ($mode === null) return;

        $vid = @$this->GetIDForIdent('IRLights');
        if ($vid !== false) {
            $val = ($mode === 'off' ? 0 : ($mode === 'on' ? 1 : 2));
            if ((int)GetValue($vid) !== $val) {
                $this->SetValue('IRLights', $val);
            }
        }
    }

    // ---------------------------
    // Online-Status
    // ---------------------------

    private function UpdateOnlineStatus(): void
    {
        $id = @$this->GetIDForIdent('KameraOnline');
        if ($id === false) return;

        $ip   = trim($this->ReadPropertyString('CameraIP'));
        $user = urlencode($this->ReadPropertyString('Username'));
        $pass = urlencode($this->ReadPropertyString('Password'));

        $isOnline = false;

        if ($ip !== '') {
            if (function_exists('Sys_Ping')) {
                $isOnline = @Sys_Ping($ip, 1000); // 1s
            }
        }

        $this->dbg('ONLINE', 'Status geprüft', ['ip' => $ip, 'online' => $isOnline]);

        if ((bool)GetValue($id) !== $isOnline) {
            $this->SetValue('KameraOnline', $isOnline);
        }
    }
}