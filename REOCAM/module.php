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

        $this->RegisterPropertyBoolean("ShowWebhookVariables", true);
        $this->RegisterPropertyBoolean("ShowBooleanVariables", true);
        $this->RegisterPropertyBoolean("ShowSnapshots", true);

        $this->RegisterHook('/hook/reolink');
        
        // Initialisieren von Booleans und Variablen
        $this->InitializeVariablesAndTimers();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");

        // Variablen, Booleans oder Snapshots löschen/erstellen, wenn Schalter aktiviert/deaktiviert sind
        $this->CleanupBasedOnSettings();
        $this->InitializeVariablesAndTimers();
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

    private function InitializeVariablesAndTimers()
    {
        if ($this->ReadPropertyBoolean("ShowBooleanVariables")) {
            $this->RegisterBooleanAndTimer("Person", "Person erkannt", 20);
            $this->RegisterBooleanAndTimer("Tier", "Tier erkannt", 25);
            $this->RegisterBooleanAndTimer("Fahrzeug", "Fahrzeug erkannt", 30);
            $this->RegisterBooleanAndTimer("Bewegung", "Bewegung allgemein", 35);
            $this->RegisterBooleanAndTimer("Test", "Test", 40);
        }

        if ($this->ReadPropertyBoolean("ShowWebhookVariables")) {
            $this->RegisterVariableString("type", "Alarm Typ", "", 15);
        }
    }

    private function CleanupBasedOnSettings()
    {
        if (!$this->ReadPropertyBoolean("ShowWebhookVariables")) {
            @$this->UnregisterVariable("type");
        }

        if (!$this->ReadPropertyBoolean("ShowBooleanVariables")) {
            $booleanVariables = ["Person", "Tier", "Fahrzeug", "Bewegung", "Test"];
            foreach ($booleanVariables as $var) {
                @$this->UnregisterVariable($var);
                $this->RemoveTimer($var . "_Reset");
            }
        }

        if (!$this->ReadPropertyBoolean("ShowSnapshots")) {
            $snapshotVariables = ["Snapshot_Person", "Snapshot_Tier", "Snapshot_Fahrzeug", "Snapshot_Bewegung", "Snapshot_Test"];
            foreach ($snapshotVariables as $snapshot) {
                $mediaID = @IPS_GetObjectIDByIdent($snapshot, $this->InstanceID);
                if ($mediaID !== false) {
                    IPS_DeleteMedia($mediaID, true);
                }
            }
        }
    }

    private function RegisterBooleanAndTimer($ident, $name, $position)
    {
        $this->RegisterVariableBoolean($ident, $name, "~Motion", $position);
        $this->EnsureTimer($ident . "_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "' . $ident . '");');
    }

    private function EnsureTimer($timerName, $interval, $script)
    {
        $timerID = @IPS_GetObjectIDByIdent($timerName, $this->InstanceID);
        if ($timerID === false) {
            $this->RegisterTimer($timerName, $interval, $script);
        } else {
            $this->SetTimerInterval($timerID, $interval);
        }
    }

    private function RemoveTimer($timerName)
    {
        $timerID = @IPS_GetObjectIDByIdent($timerName, $this->InstanceID);
        if ($timerID !== false) {
            IPS_DeleteTimer($timerID);
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
            if ($this->ReadPropertyBoolean("ShowWebhookVariables")) {
                $variablesToUpdate["type"] = $type;
            }

            if ($this->ReadPropertyBoolean("ShowBooleanVariables")) {
                switch ($type) {
                    case "PEOPLE":
                        $this->ActivateBoolean("Person", "Person_Reset");
                        break;
                    case "ANIMAL":
                        $this->ActivateBoolean("Tier", "Tier_Reset");
                        break;
                    case "VEHICLE":
                        $this->ActivateBoolean("Fahrzeug", "Fahrzeug_Reset");
                        break;
                    case "MD":
                        $this->ActivateBoolean("Bewegung", "Bewegung_Reset");
                        break;
                    case "TEST":
                        $this->ActivateBoolean("Test", "Test_Reset");
                        break;
                    default:
                        $this->SendDebug("Unknown Type", "Der Typ $type ist unbekannt.", 0);
                        break;
                }
            }
        }

        foreach ($data['alarm'] as $key => $value) {
            if ($key !== 'type' && $this->ReadPropertyBoolean("ShowWebhookVariables")) {
                $variablesToUpdate[$key] = $value;
            }
        }

        foreach ($variablesToUpdate as $name => $value) {
            $this->updateVariable($name, $value);
        }
    }

    private function ActivateBoolean($ident, $timerName)
    {
        $this->SetValue($ident, true);
        $this->SetTimerInterval($timerName, 5000);

        if ($this->ReadPropertyBoolean("ShowSnapshots")) {
            $this->CreateSnapshotAtPosition($ident);
        }
    }

    public function ResetBoolean(string $ident)
    {
        $this->SetValue($ident, false);
        $this->SetTimerInterval($ident . "_Reset", 0);
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

    private function CreateSnapshotAtPosition($booleanIdent)
    {
        $snapshotIdent = "Snapshot_" . $booleanIdent;
        $mediaID = @IPS_GetObjectIDByIdent($snapshotIdent, $this->InstanceID);

        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(1);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $snapshotIdent);
            IPS_SetPosition($mediaID, $this->GetPositionForBoolean($booleanIdent) + 1);
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

    private function GetPositionForBoolean($ident)
    {
        switch ($ident) {
            case "Person":
                return 20;
            case "Tier":
                return 25;
            case "Fahrzeug":
                return 30;
            case "Bewegung":
                return 35;
            case "Test":
                return 40;
            default:
                return 0;
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
