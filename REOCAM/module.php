<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        parent::Create();
        
        $this->RegisterPropertyString("CameraIP", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("StreamType", "sub");

        $this->RegisterHook('/hook/reolink');

        $this->RegisterVariableBoolean("Person", "Person erkannt", "~Motion", 20);
        $this->RegisterVariableBoolean("Tier", "Tier erkannt", "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug erkannt", "~Motion", 30);
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion", 35);
        $this->RegisterVariableBoolean("Test", "Test", "~Motion", 40);

        $this->RegisterTimer("Person_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Person");');
        $this->RegisterTimer("Tier_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Tier");');
        $this->RegisterTimer("Fahrzeug_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Fahrzeug");');
        $this->RegisterTimer("Bewegung_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Bewegung");');
        $this->RegisterTimer("Test_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Test");');

        $this->RegisterVariableString("type", "Alarm Typ", "", 15);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");
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
                // Alle Variablen in einem Schritt speichern
                $this->ProcessAllData($data);
            } else {
                $this->SendDebug('JSON Decoding Error', 'Die empfangenen Rohdaten konnten nicht als JSON decodiert werden.', 0);
            }
        } else {
            IPS_LogMessage("Reolink", "Keine Daten empfangen oder Datenstrom ist leer.");
            $this->SendDebug("Reolink", "Keine Daten empfangen oder Datenstrom ist leer.", 0);
        }
    }

    private function ProcessAllData($data)
    {
        $variablesToUpdate = [];

        if (isset($data['alarm']['type'])) {
            $type = $data['alarm']['type'];
            $variablesToUpdate["type"] = $type;

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
                case "TEST":
                    $this->ActivateBoolean("Test", 41);
                    break;
                default:
                    $this->SendDebug("Unknown Type", "Der Typ $type ist unbekannt.", 0);
                    break;
            }
        }

        foreach ($data['alarm'] as $key => $value) {
            if ($key !== 'type') {
                $variablesToUpdate[$key] = $value;
            }
        }

        foreach ($variablesToUpdate as $name => $value) {
            $this->updateVariable($name, $value);
        }
    }

    private function ActivateBoolean($ident, $position)
    {
        $timerName = $ident . "_Reset";
        $this->SendDebug('ActivateBoolean', "Schalte Boolean $ident auf true und starte Snapshot.", 0);

        $this->SetValue($ident, true);
        $this->CreateSnapshotAtPosition($ident, $position);
        $this->SetTimerInterval($timerName, 5000);
    }

    public function ResetBoolean(string $ident)
    {
        $timerName = $ident . "_Reset";
        $this->SendDebug('ResetBoolean', "Timer abgelaufen, setze Boolean $ident auf false.", 0);
        $this->SetValue($ident, false);
        $this->SetTimerInterval($timerName, 0);
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

    private function CreateSnapshotAtPosition($booleanIdent, $position)
    {
        $snapshotIdent = "Snapshot_" . $booleanIdent;
        $mediaID = @IPS_GetObjectIDByIdent($snapshotIdent, $this->InstanceID);

        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(1);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $snapshotIdent);
            IPS_SetPosition($mediaID, $position);
            IPS_SetName($mediaID, "Snapshot von " . $booleanIdent);
            IPS_SetMediaCached($mediaID, false);
        }

        $snapshotUrl = $this->GetSnapshotURL();
        $tempImagePath = IPS_GetKernelDir() . "media/snapshot_temp_" . $booleanIdent . ".jpg";
        $imageData = @file_get_contents($snapshotUrl);

        if ($imageData !== false) {
            file_put_contents($tempImagePath, $imageData);
            IPS_SetMediaFile($mediaID, $tempImagePath, false);
            IPS_SendMediaEvent($mediaID);
        } else {
            IPS_LogMessage("Reolink", "Snapshot konnte nicht abgerufen werden für $booleanIdent.");
        }
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
