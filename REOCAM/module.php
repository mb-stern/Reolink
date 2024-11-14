<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Moduleigenschaften
        $this->RegisterPropertyString("CameraIP", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("StreamType", "sub");
        $this->RegisterPropertyInteger("MaxArchiveImages", 20); // Max. Bilder im Archiv

        // Schalter für Variablen und Archiv
        $this->RegisterPropertyBoolean("ShowWebhookVariables", true);
        $this->RegisterPropertyBoolean("ShowBooleanVariables", true);
        $this->RegisterPropertyBoolean("ShowSnapshots", true);
        $this->RegisterPropertyBoolean("ShowArchives", true);

        // Attribut für den aktuellen Webhook
        $this->RegisterAttributeString("CurrentHook", ""); // Initial leer

        // Webhook registrieren
        $this->RegisterHook();

        // Boolean-Variablen registrieren
        $this->RegisterVariableBoolean("Person", "Person erkannt", "~Motion", 20);
        $this->RegisterVariableBoolean("Tier", "Tier erkannt", "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug erkannt", "~Motion", 30);
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion", 35);

        // Timer zur Rücksetzung
        $this->RegisterTimer("Person_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Person");');
        $this->RegisterTimer("Tier_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Tier");');
        $this->RegisterTimer("Fahrzeug_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Fahrzeug");');
        $this->RegisterTimer("Bewegung_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Bewegung");');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterHook();

        if ($this->ReadPropertyBoolean("ShowWebhookVariables")) {
            $this->CreateWebhookVariables();
        } else {
            $this->RemoveWebhookVariables();
        }

        if ($this->ReadPropertyBoolean("ShowBooleanVariables")) {
            $this->CreateBooleanVariables();
        } else {
            $this->RemoveBooleanVariables();
        }

        if ($this->ReadPropertyBoolean("ShowSnapshots")) {
            $this->CreateOrUpdateSnapshots();
        } else {
            $this->RemoveSnapshots();
        }

        if ($this->ReadPropertyBoolean("ShowArchives")) {
            $this->CreateOrUpdateArchives();
        } else {
            $this->RemoveArchives();
        }
    }

    private function CreateOrUpdateArchives()
    {
        $categories = ["Person", "Tier", "Fahrzeug", "Bewegung"];
        foreach ($categories as $category) {
            $categoryID = @IPS_GetObjectIDByIdent("Archive_" . $category, $this->InstanceID);

            if ($categoryID === false) {
                $categoryID = IPS_CreateCategory();
                IPS_SetParent($categoryID, $this->InstanceID);
                IPS_SetIdent($categoryID, "Archive_" . $category);
                IPS_SetName($categoryID, "Bildarchiv " . $category);
            }

            $snapshotID = @IPS_GetObjectIDByIdent("Snapshot_" . $category, $this->InstanceID);
            if ($snapshotID !== false) {
                $this->CopySnapshotToArchive($snapshotID, $categoryID, $this->ReadPropertyInteger("MaxArchiveImages"));
            }
        }
    }

    private function RemoveArchives()
    {
        $categories = ["Person", "Tier", "Fahrzeug", "Bewegung"];
        foreach ($categories as $category) {
            $categoryID = @IPS_GetObjectIDByIdent("Archive_" . $category, $this->InstanceID);
            if ($categoryID !== false) {
                IPS_DeleteCategory($categoryID);
            }
        }
    }

    private function CopySnapshotToArchive($snapshotID, $categoryID, $maxImages)
    {
        $snapshot = IPS_GetMedia($snapshotID);
        $snapshotPath = $snapshot['MediaFile'];
    
        if (file_exists($snapshotPath)) {
            $archiveFileName = "archive_" . time() . ".jpg";
            $archiveFilePath = IPS_GetKernelDir() . "media/" . $archiveFileName;
    
            // Datei kopieren
            if (copy($snapshotPath, $archiveFilePath)) {
                // Neues Medienobjekt im Archiv erstellen
                $mediaID = IPS_CreateMedia(1);
                IPS_SetParent($mediaID, $categoryID);
                IPS_SetName($mediaID, "Snapshot " . date("Y-m-d H:i:s"));
                IPS_SetPosition($mediaID, -time()); // Negative Zeit für neueste zuerst
                IPS_SetMediaFile($mediaID, $archiveFilePath, false);
    
                // Debug: Erfolgreiches Kopieren
                $this->SendDebug("CopySnapshotToArchive", "Snapshot erfolgreich ins Archiv kopiert: $archiveFilePath", 0);
    
                // Archivgröße beschränken
                $this->PruneArchive($categoryID, $maxImages);
            } else {
                IPS_LogMessage("Reolink", "Fehler beim Kopieren der Datei $snapshotPath.");
            }
        } else {
            IPS_LogMessage("Reolink", "Schnappschuss-Datei $snapshotPath existiert nicht.");
        }
    }
    
    private function PruneArchive($categoryID, $maxImages)
    {
        $children = IPS_GetChildrenIDs($categoryID);
    
        // Falls zu viele Bilder im Archiv, entferne die ältesten
        if (count($children) > $maxImages) {
            usort($children, function ($a, $b) {
                return IPS_GetObject($a)['ObjectPosition'] <=> IPS_GetObject($b)['ObjectPosition'];
            });
    
            while (count($children) > $maxImages) {
                $oldestID = array_shift($children);
                IPS_DeleteMedia($oldestID, true);
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
    
            // Debugging: Neues Medienobjekt erstellt
            $this->SendDebug('CreateSnapshotAtPosition', "Neues Medienobjekt für Snapshot von $booleanIdent erstellt.", 0);
        } else {
            // Debugging: Vorhandenes Medienobjekt gefunden
            $this->SendDebug('CreateSnapshotAtPosition', "Vorhandenes Medienobjekt für Snapshot von $booleanIdent gefunden.", 0);
        }
    
        $snapshotUrl = $this->GetSnapshotURL();
        $tempImagePath = IPS_GetKernelDir() . "media/snapshot_temp_" . $booleanIdent . ".jpg";
        $imageData = @file_get_contents($snapshotUrl);
    
        if ($imageData !== false) {
            file_put_contents($tempImagePath, $imageData);
            IPS_SetMediaFile($mediaID, $tempImagePath, false);
            IPS_SendMediaEvent($mediaID);
    
            // Debugging: Snapshot erfolgreich erstellt
            $this->SendDebug('CreateSnapshotAtPosition', "Snapshot für $booleanIdent erfolgreich erstellt.", 0);
    
            // Archivieren
            $archiveID = @IPS_GetObjectIDByIdent("Archive_" . $booleanIdent, $this->InstanceID);
            if ($archiveID !== false) {
                $this->CopySnapshotToArchive($mediaID, $archiveID, $this->ReadPropertyInteger("MaxArchiveImages"));
            }
        } else {
            // Debugging: Fehler beim Abrufen des Snapshots
            $this->SendDebug('CreateSnapshotAtPosition', "Fehler beim Abrufen des Snapshots für $booleanIdent.", 0);
            IPS_LogMessage("Reolink", "Snapshot konnte nicht abgerufen werden für $booleanIdent.");
        }
    }
    
    private function RegisterHook()
    {
        $hookName = '/hook/reolink'; // Fester Name für den Webhook
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');

        if (count($ids) === 0) {
            $this->SendDebug('RegisterHook', 'Keine WebHook-Control-Instanz gefunden.', 0);
            return;
        }

        $hookInstanceID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookInstanceID, 'Hooks'), true);

        if (!is_array($hooks)) {
        $hooks = [];
        }

        // Prüfen, ob der Hook bereits existiert
        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $hookName && $hook['TargetID'] === $this->InstanceID) {
                $this->SendDebug('RegisterHook', "Hook '$hookName' ist bereits registriert.", 0);
                return; // Hook existiert bereits, keine weiteren Aktionen nötig
            }
        }

        // Falls der Hook nicht existiert, hinzufügen
        $hooks[] = ['Hook' => $hookName, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookInstanceID);

        $this->SendDebug('RegisterHook', "Hook '$hookName' wurde registriert.", 0);
    }

    public function ProcessHookData()
    {
        $rawData = file_get_contents("php://input");
        $this->SendDebug('Webhook Triggered', 'Reolink Webhook wurde ausgelöst', 0);

        if (!empty($rawData)) {
            $this->SendDebug('Raw Webhook Data', $rawData, 0); // Zeigt das empfangene JSON
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

    // Debugging hinzufügen
    $this->SendDebug('ActivateBoolean', "Schalte Boolean $ident auf true.", 0);

    $this->SetValue($ident, true);

    if ($this->ReadPropertyBoolean("ShowSnapshots")) {
        $this->CreateSnapshotAtPosition($ident, $position);
    }

    // Debugging für den Timer
    $this->SendDebug('ActivateBoolean', "Setze Timer $timerName auf 5 Sekunden.", 0);
    $this->SetTimerInterval($timerName, 5000);
}

public function ResetBoolean(string $ident)
{
    $timerName = $ident . "_Reset";

    // Debugging hinzufügen
    $this->SendDebug('ResetBoolean', "Setze Boolean $ident auf false.", 0);

    $this->SetValue($ident, false);
    $this->SetTimerInterval($timerName, 0);
}


    private function CreateWebhookVariables()
    {
        $webhookVariables = [
            "type" => "Alarm Typ",
            "message" => "Alarm Nachricht",
            "title" => "Alarm Titel",
            "device" => "Gerätename",
            "channel" => "Kanal",
            "alarmTime" => "Alarmzeit",
            "channelName" => "Kanalname",
            "deviceModel" =>  "Gerätemodell",
            "name" => "Name"
        ];

        foreach ($webhookVariables as $ident => $name) {
            if (!IPS_VariableExists(@IPS_GetObjectIDByIdent($ident, $this->InstanceID))) {
                $this->RegisterVariableString($ident, $name);
            }
        }
    }

    private function RemoveWebhookVariables()
    {
        $webhookVariables = ["type", "message", "title", "device", "channel", "alarmTime", "channelName", "deviceModel", "name"];
        foreach ($webhookVariables as $ident) {
            $varID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($varID !== false) {
                $this->UnregisterVariable($ident);
            }
        }
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
        $booleans = ["Person", "Tier", "Fahrzeug", "Bewegung", "Test"];
        foreach ($booleans as $booleanIdent) {
            $varID = @IPS_GetObjectIDByIdent($booleanIdent, $this->InstanceID);
            if ($varID !== false) {
                $this->UnregisterVariable($booleanIdent);
            }
        }
    }

    private function CreateOrUpdateSnapshots()
    {
        $snapshots = ["Person", "Tier", "Fahrzeug", "Test", "Bewegung"];
        foreach ($snapshots as $snapshot) {
            $booleanID = @IPS_GetObjectIDByIdent($snapshot, $this->InstanceID);
            $position = $booleanID !== false ? IPS_GetObject($booleanID)['ObjectPosition'] + 1 : 0;
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

        // Debugging: Neues Medienobjekt erstellt
        $this->SendDebug('CreateSnapshotAtPosition', "Neues Medienobjekt für Snapshot von $booleanIdent erstellt.", 0);
    } else {
        // Debugging: Vorhandenes Medienobjekt gefunden
        $this->SendDebug('CreateSnapshotAtPosition', "Vorhandenes Medienobjekt für Snapshot von $booleanIdent gefunden.", 0);
    }

    $snapshotUrl = $this->GetSnapshotURL();
    $tempImagePath = IPS_GetKernelDir() . "media/snapshot_temp_" . $booleanIdent . ".jpg";
    $imageData = @file_get_contents($snapshotUrl);

    if ($imageData !== false) {
        file_put_contents($tempImagePath, $imageData);
        IPS_SetMediaFile($mediaID, $tempImagePath, false);
        IPS_SendMediaEvent($mediaID);

        // Debugging: Snapshot erfolgreich erstellt
        $this->SendDebug('CreateSnapshotAtPosition', "Snapshot für $booleanIdent erfolgreich erstellt.", 0);
    } else {
        // Debugging: Fehler beim Abrufen des Snapshots
        $this->SendDebug('CreateSnapshotAtPosition', "Fehler beim Abrufen des Snapshots für $booleanIdent.", 0);
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