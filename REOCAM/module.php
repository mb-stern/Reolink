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
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');
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
    }

    private function ProcessData($data)
    {
        if (isset($data['alarm']['type'])) {
            $type = $data['alarm']['type'];
            switch ($type) {
                case "PEOPLE":
                    $this->ActivateBoolean("Person", 21);
                    break;
                case "ANIMAL":
                    $this->ActivateBoolean("Tier", 26);
                    break;
                case "VEHICLE":
                    $this->ActivateBoolean("Fahrzeug", 31);
                    break;
                case "MD":
                    $this->ActivateBoolean("Bewegung", 36);
                    break;
                default:
                    $this->SendDebug("Unknown Type", "Der Typ $type ist unbekannt.", 0);
                    break;
            }
        }
    }

    private function ActivateBoolean($ident, $position)
    {
        $this->SetValue($ident, true);

        // Snapshot unterhalb der Position der Boolean-Variable erstellen
        $this->CreateSnapshotAtPosition($ident, $position);

        // Nach 5 Sekunden wieder auf false setzen
        IPS_Sleep(5000);
        $this->SetValue($ident, false);
    }

    private function CreateSnapshotAtPosition($booleanIdent, $position)
    {
        $snapshotIdent = "Snapshot_" . $booleanIdent;
        $mediaID = @IPS_GetObjectIDByIdent($snapshotIdent, $this->InstanceID);

        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(1); // 1 steht für Bild (PNG/JPG)
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $snapshotIdent);
            IPS_SetPosition($mediaID, $position);
            IPS_SetName($mediaID, "Snapshot von " . $booleanIdent);
            IPS_SetMediaCached($mediaID, false);
        }

        // URL zum Snapshot-Bild abrufen und speichern
        $snapshotUrl = $this->GetSnapshotURL();
        $imageData = @file_get_contents($snapshotUrl);

        if ($imageData !== false) {
            IPS_SetMediaContent($mediaID, base64_encode($imageData)); // Base64-kodierte Bilddaten setzen
            IPS_SendMediaEvent($mediaID); // Medienobjekt aktualisieren
        } else {
            IPS_LogMessage("Reolink", "Snapshot konnte nicht abgerufen werden für $booleanIdent.");
        }
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
