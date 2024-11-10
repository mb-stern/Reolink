<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // IP-Adresse, Benutzername und Passwort als Eigenschaften registrieren
        $this->RegisterPropertyString("CameraIP", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");

        // Webhook registrieren
        $this->RegisterHook('/hook/reolink');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');

        // URLs für Medienobjekte aktualisieren
        $this->UpdateMediaObjects();
    }

    private function RegisterHook($Hook)
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) > 0) {
            $hookInstanceID = $ids[0];
            $hooks = json_decode(IPS_GetProperty($hookInstanceID, 'Hooks'), true);
            if (!is_array($hooks)) {
                $hooks = [];
            }
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $Hook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        $found = true;
                        break;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ['Hook' => $Hook, 'TargetID' => $this->InstanceID];
            }
            IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($hookInstanceID);
        }
    }

    public function ProcessHookData()
    {
        $rawData = file_get_contents("php://input");
        $this->SendDebug('Webhook Triggered', 'Reolink Webhook wurde ausgelöst', 0);
        $this->SendDebug('Raw POST Data', $rawData, 0);

        if (!empty($rawData)) {
            $data = json_decode($rawData, true);
            if (is_array($data)) {
                $this->ProcessData($data);
            } else {
                $this->SendDebug('JSON Decoding Error', 'Die empfangenen Rohdaten konnten nicht als JSON decodiert werden.', 0);
            }
        } else {
            IPS_LogMessage("Reolink", "Keine Daten empfangen oder Datenstrom ist leer.");
            $this->SendDebug("Reolink", "Keine Daten empfangen oder Datenstrom ist leer.", 0);
        }

        // Snapshot-Bild bei jedem Webhook-Aufruf aktualisieren
        $this->UpdateSnapshot();
    }

    private function ProcessData($data)
    {
        if (isset($data['alarm'])) {
            foreach ($data['alarm'] as $key => $value) {
                if ($key === 'alarmTime') {
                    $dateTime = new DateTime($value);
                    $dateTime->setTimezone(new DateTimeZone('Europe/Berlin'));
                    $formattedAlarmTime = $dateTime->format('Y-m-d H:i:s');
                    $this->updateVariable($key, $formattedAlarmTime, 3); // String
                } else {
                    $this->updateVariable($key, $value);
                }
            }
        }
    }

    private function updateVariable($name, $value, $type = null)
    {
        $ident = $this->normalizeIdent($name);

        if ($type === null) {
            if (is_string($value)) {
                $type = 3;
            } elseif (is_int($value)) {
                $type = 1;
            } elseif (is_float($value)) {
                $type = 2;
            } elseif (is_bool($value)) {
                $type = 0;
            } else {
                $type = 3;
                $value = json_encode($value);
            }
        }

        switch ($type) {
            case 0: // Boolean
                $this->RegisterVariableBoolean($ident, $name);
                break;
            case 1: // Integer
                $this->RegisterVariableInteger($ident, $name);
                break;
            case 2: // Float
                $this->RegisterVariableFloat($ident, $name);
                break;
            case 3: // String
                $this->RegisterVariableString($ident, $name);
                break;
        }

        $this->SetValue($ident, $value);
    }

    private function normalizeIdent($name)
    {
        $ident = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        return substr($ident, 0, 32);
    }

    private function UpdateMediaObjects()
    {
        $this->UpdateMediaObject("StreamURL", $this->GetStreamURL(), true);
        $this->UpdateSnapshot();
    }

    private function UpdateSnapshot()
    {
        $snapshotUrl = $this->GetSnapshotURL();
        $mediaID = $this->UpdateMediaObject("Snapshot", $snapshotUrl, false);

        if ($mediaID !== false) {
            $imageData = @file_get_contents($snapshotUrl);
            if ($imageData !== false) {
                IPS_SetMediaContent($mediaID, base64_encode($imageData));
                IPS_ApplyChanges($mediaID);
            } else {
                IPS_LogMessage("Reolink", "Snapshot konnte nicht abgerufen werden.");
            }
        }
    }

    private function UpdateMediaObject($ident, $url, $isStream)
    {
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        
        // Falls das Medienobjekt nicht existiert, wird es erstellt
        if ($mediaID === false) {
            $mediaID = $isStream ? IPS_CreateMedia(3) : IPS_CreateMedia(1); // 3 für Stream, 1 für Bild
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $isStream ? "Kamera Stream" : "Kamera Snapshot");
            IPS_SetMediaCached($mediaID, true);
        }

        // URL für Stream aktualisieren, falls isStream = true
        if ($isStream) {
            IPS_SetMediaFile($mediaID, $url, false);
        }

        return $mediaID;
    }

    public function GetStreamURL()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");

        return "rtsp://$username:$password@$cameraIP:554//h264Preview_01_sub";
    }

    public function GetSnapshotURL()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");

        return "http://$cameraIP/cgi-bin/api.cgi?cmd=Snap&user=$username&password=$password";
    }
}

?>
