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

        // Schalter zum Ein- und Ausblenden von Variablen und Snapshots
        $this->RegisterPropertyBoolean("ShowWebhookVariables", true);
        $this->RegisterPropertyBoolean("ShowBooleanVariables", true);
        $this->RegisterPropertyBoolean("ShowSnapshots", true);

        // Webhook registrieren
        $this->RegisterHook('/hook/reolink');

        // Initialisierung von Variablen und Timern
        $this->CreateBooleanVariables();
        $this->RegisterTimer("Person_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Person");');
        $this->RegisterTimer("Tier_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Tier");');
        $this->RegisterTimer("Fahrzeug_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Fahrzeug");');
        $this->RegisterTimer("Bewegung_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Bewegung");');
        $this->RegisterTimer("Test_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Test");');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');

        // Snapshots je nach Einstellung anzeigen oder löschen
        if ($this->ReadPropertyBoolean("ShowSnapshots")) {
            $this->CreateOrUpdateSnapshots();
        } else {
            $this->RemoveSnapshots();
        }

        // Boolean-Variablen je nach Einstellung anzeigen oder löschen
        if ($this->ReadPropertyBoolean("ShowBooleanVariables")) {
            $this->CreateBooleanVariables();
        } else {
            $this->RemoveBooleanVariables();
        }

        // Webhook-Variablen je nach Einstellung anzeigen oder löschen
        if ($this->ReadPropertyBoolean("ShowWebhookVariables")) {
            $this->CreateWebhookVariables();
        } else {
            $this->RemoveWebhookVariables();
        }

        // Stream-URL aktualisieren
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
                if ($hook['Hook'] == $Hook && $hook['TargetID'] == $this->InstanceID) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $hooks[] = ['Hook' => $Hook, 'TargetID' => $this->InstanceID];
                IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($hookInstanceID);
            }
        }
    }

    public function ProcessHookData()
    {
        $rawData = file_get_contents("php://input");
        $this->SendDebug('Webhook Triggered', 'Reolink Webhook wurde ausgelöst', 0);

        if (!empty($rawData)) {
            $data = json_decode($rawData, true);
            if (is_array($data)) {
                $this->ProcessAllData($data);
            }
        }
    }

    private function ProcessAllData($data)
    {
        if (isset($data['alarm']['type'])) {
            $type = $data['alarm']['type'];
            $this->SetValue("type", $type);

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
            }
        }

        // Aktualisieren der Webhook-Variablen, falls aktiviert
        if ($this->ReadPropertyBoolean("ShowWebhookVariables")) {
            foreach ($data['alarm'] as $key => $value) {
                if ($key !== 'type') {
                    $this->updateVariable($key, $value);
                }
            }
        }
    }

    private function ActivateBoolean($ident, $position)
    {
        $timerName = $ident . "_Reset";
        $this->SetValue($ident, true);

        // Snapshot erstellen, falls aktiviert
        if ($this->ReadPropertyBoolean("ShowSnapshots")) {
            $this->CreateSnapshotAtPosition($ident, $position);
        }

        $this->SetTimerInterval($timerName, 5000);
    }

    public function ResetBoolean(string $ident)
    {
        $timerName = $ident . "_Reset";
        $this->SetValue($ident, false);
        $this->SetTimerInterval($timerName, 0);
    }

    private function CreateBooleanVariables()
    {
        $this->RegisterVariableBoolean("Person", "Person erkannt", "~Motion", 20);
        $this->RegisterVariableBoolean("Tier", "Tier erkannt", "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug erkannt", "~Motion", 30);
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion", 35);
        $this->RegisterVariableBoolean("Test", "Test", "~Motion", 40);
    }

    private function RemoveBooleanVariables()
    {
        $booleanVariables = ["Person", "Tier", "Fahrzeug", "Bewegung", "Test"];
        foreach ($booleanVariables as $variable) {
            $varID = @IPS_GetObjectIDByIdent($variable, $this->InstanceID);
            if ($varID !== false) {
                $this->UnregisterVariable($varID);
            }
        }
    }

    private function CreateWebhookVariables()
{
    // Array aller gewünschten Webhook-Variablen
    $webhookVariables = [
        "type" => "Alarm Typ",
        "message" => "Alarm Nachricht",
        "title" => "Alarm Titel",
        "device" => "Gerätename",
        "channel" => "Kanal",
        "alarmTime" => "Alarmzeit"
    ];
    
    foreach ($webhookVariables as $ident => $name) {
        // Prüfen, ob die Variable bereits existiert
        if (@IPS_GetObjectIDByIdent($ident, $this->InstanceID) === false) {
            $this->RegisterVariableString($ident, $name);
        }
    }
}

private function RemoveWebhookVariables()
{
    // Array aller gewünschten Webhook-Variablen
    $webhookVariables = [
        "type",
        "message",
        "title",
        "device",
        "channel",
        "alarmTime"
    ];
    
    foreach ($webhookVariables as $ident) {
        $varID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($varID !== false) {
            $this->UnregisterVariable($varID);
        }
    }
}


    private function CreateOrUpdateSnapshots()
    {
        $snapshots = ["Person", "Tier", "Fahrzeug", "Test", "Bewegung"];
        foreach ($snapshots as $snapshot) {
            $booleanID = @IPS_GetObjectIDByIdent($snapshot, $this->InstanceID);
            $position = $booleanID !== false ? IPS_GetObject($booleanID)['ObjectPosition'] + 1 : 0;
            $this->CreateSnapshotAtPosition($snapshot, $position);
        }
    }

    private function RemoveSnapshots()
    {
        $snapshots = ["Snapshot_Person", "Snapshot_Tier", "Snapshot_Fahrzeug", "Snapshot_Test", "Snapshot_Bewegung"];
        foreach ($snapshots as $snapshotIdent) {
            $mediaID = @IPS_GetObjectIDByIdent($snapshotIdent, $this->InstanceID);
            if ($mediaID) {
                IPS_DeleteMedia($mediaID, true);
            }
        }
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
            IPS_SetPosition($mediaID, 10);
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
