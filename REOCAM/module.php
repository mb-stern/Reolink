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
        $this->RegisterPropertyString("StreamType", "sub");

        // Webhook registrieren
        $this->RegisterHook('/hook/reolink');

        // Bool-Variablen mit dem Variablenprofil "~Motion" erstellen und Positionen festlegen
        $this->RegisterVariableBoolean("Person", "Person erkannt", "~Motion", 20);
        $this->RegisterVariableBoolean("Tier", "Tier erkannt", "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug erkannt", "~Motion", 30);
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion", 35);
        $this->RegisterVariableBoolean("Test", "Test", "~Motion", 40);

        // String-Variable `type` für Alarmtyp
        $this->RegisterVariableString("type", "Alarm Typ", "", 15);

        // Timer für Verzögerung registrieren
        $this->RegisterTimer("ResetBooleanTimer", 0, 'Reolink_ResetBoolean($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');

        // Aktualisiere den Stream, falls der StreamType geändert wurde
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");
    }

    private function RegisterHook($Hook)
    {
        $ids = IPS_GetInstanceListByModuleID('{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}');
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
    }

    private function ProcessData($data)
    {
        if (isset($data['alarm']['type'])) {
            $type = $data['alarm']['type'];

            // Setze die `type`-Variable, um den aktuellen Typ zu speichern
            $this->SetValue("type", $type);

            switch ($type) {
                case "PEOPLE":
                    $this->ActivateBoolean("Person");
                    break;
                case "ANIMAL":
                    $this->ActivateBoolean("Tier");
                    break;
                case "VEHICLE":
                    $this->ActivateBoolean("Fahrzeug");
                    break;
                case "MD":
                    $this->ActivateBoolean("Bewegung");
                    break;
                case "TEST":
                    $this->ActivateBoolean("Test");
                    break;
                default:
                    $this->SendDebug("Unknown Type", "Der Typ $type ist unbekannt.", 0);
                    break;
            }
        }

        // Zusätzliche Variablen aus dem Webhook-JSON in IP-Symcon-Variablen speichern
        foreach ($data['alarm'] as $key => $value) {
            if ($key !== 'type') { // `type` wird bereits behandelt
                $this->updateVariable($key, $value);
            }
        }
    }

    private function ActivateBoolean($ident)
    {
        $this->SetValue($ident, true);

        // Timer für das Zurücksetzen nach 5 Sekunden starten
        $this->SetTimerInterval("ResetBooleanTimer", 5000);
    }

    public function ResetBoolean()
    {
        // Setzt die Boolean-Variablen zurück und deaktiviert den Timer
        $this->SetValue("Person", false);
        $this->SetValue("Tier", false);
        $this->SetValue("Fahrzeug", false);
        $this->SetValue("Bewegung", false);
        $this->SetValue("Test", false);
        $this->SetTimerInterval("ResetBooleanTimer", 0);
    }

    private function updateVariable($name, $value)
    {
        $ident = $this->normalizeIdent($name);

        if (is_string($value)) {
            $this->RegisterVariableString($ident, $name);
            $this->SetValue($ident, $value);
        } elseif (is_int($value)) {
            $this->RegisterVariableInteger($ident, $name);
            $this->SetValue($ident, $value);
        } elseif (is_float($value)) {
            $this->RegisterVariableFloat($ident, $name);
            $this->SetValue($ident, $value);
        } elseif (is_bool($value)) {
            $this->RegisterVariableBoolean($ident, $name);
            $this->SetValue($ident, $value);
        } else {
            $this->RegisterVariableString($ident, $name);
            $this->SetValue($ident, json_encode($value));
        }
    }

    private function normalizeIdent($name)
    {
        $ident = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        return substr($ident, 0, 32); 
    }

    private function CreateOrUpdateStream($ident, $name)
    {
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(3);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $name);
            IPS_SetMediaCached($mediaID, true);
        }

        IPS_SetMediaFile($mediaID, $this->GetStreamURL(), false);
    }

    public function GetStreamURL()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");
        $streamType = $this->ReadPropertyString("StreamType");

        return $streamType === "main" ? 
               "rtsp://$username:$password@$cameraIP:554" :
               "rtsp://$username:$password@$cameraIP:554//h264Preview_01_sub";
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
