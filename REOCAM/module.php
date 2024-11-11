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

        // Bool-Variablen mit dem Variablenprofil "~Motion" erstellen
        $this->RegisterVariableBoolean("Person", "Person erkannt", "~Motion");
        $this->RegisterVariableBoolean("Tier", "Tier erkannt", "~Motion");
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug erkannt ", "~Motion");
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');

        // Sicherstellen, dass die Medienobjekte für den Stream und das Bild existieren oder erstellt werden
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");
        $this->CreateOrUpdateImage("Snapshot", "Kamera Snapshot");
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
        if (isset($data['alarm']['type'])) {
            $type = $data['alarm']['type'];
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
                default:
                    $this->SendDebug("Unknown Type", "Der Typ $type ist unbekannt.", 0);
                    break;
            }
        }
    }

    private function ActivateBoolean($ident)
    {
        $this->SetValue($ident, true);

        // Nach 5 Sekunden wieder auf false setzen
        IPS_Sleep(5000);
        $this->SetValue($ident, false);
    }

    private function CreateOrUpdateStream($ident, $name)
    {
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(3); // 3 steht für Stream
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $name);
            IPS_SetMediaCached($mediaID, true);
        }

        IPS_SetMediaFile($mediaID, $this->GetStreamURL(), false);
    }

    private function CreateOrUpdateImage($ident, $name)
    {
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(1); // 1 steht für Bild (PNG/JPG)
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $name);
            IPS_SetMediaCached($mediaID, false);
        }

        return $mediaID;
    }

    private function UpdateSnapshot()
    {
        $mediaID = $this->CreateOrUpdateImage("Snapshot", "Kamera Snapshot");
        $snapshotUrl = $this->GetSnapshotURL();
        $tempImagePath = IPS_GetKernelDir() . "media/snapshot_temp.jpg";
        $imageData = @file_get_contents($snapshotUrl);

        if ($imageData !== false) {
            file_put_contents($tempImagePath, $imageData);
            IPS_SetMediaFile($mediaID, $tempImagePath, false);
            IPS_SendMediaEvent($mediaID);
        } else {
            IPS_LogMessage("Reolink", "Snapshot konnte nicht abgerufen werden.");
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
