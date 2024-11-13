<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Moduleigenschaften registrieren
        $this->RegisterPropertyString("CameraIP", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("StreamType", "sub");

        // Einstellungen für Variablen, Schnappschüsse und Archive
        $this->RegisterPropertyBoolean("ShowWebhookVariables", true);
        $this->RegisterPropertyBoolean("ShowBooleanVariables", true);
        $this->RegisterPropertyBoolean("ShowSnapshots", true);
        $this->RegisterPropertyBoolean("ShowArchives", true);
        $this->RegisterPropertyInteger("MaxArchiveImages", 20);

        // Attribut für den aktuellen Webhook registrieren
        $this->RegisterAttributeString("CurrentHook", "");

        // Webhook registrieren
        $this->RegisterHook();

        // Standard-Boolean-Variablen für Bewegungen registrieren
        $this->RegisterVariableBoolean("Person", "Person erkannt", "~Motion", 20);
        $this->RegisterVariableBoolean("Tier", "Tier erkannt", "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug erkannt", "~Motion", 30);
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion", 35);
        $this->RegisterVariableBoolean("Test", "Test", "~Motion", 40);

        // Timer zur Rücksetzung der Boolean-Variablen
        $this->RegisterTimer("Person_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Person");');
        $this->RegisterTimer("Tier_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Tier");');
        $this->RegisterTimer("Fahrzeug_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Fahrzeug");');
        $this->RegisterTimer("Bewegung_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Bewegung");');
        $this->RegisterTimer("Test_Reset", 0, 'REOCAM_ResetBoolean($_IPS[\'TARGET\'], "Test");');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Verwalte Webhook- und Boolean-Variablen sowie Schnappschüsse
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

        // Verwalte Bildarchive
        if ($this->ReadPropertyBoolean("ShowArchives")) {
            $this->CreateOrUpdateArchives();
        } else {
            $this->RemoveArchives();
        }

        // Stream-URL aktualisieren
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");
    }

    private function CreateOrUpdateArchives()
    {
        $archiveNames = ["Person", "Tier", "Fahrzeug", "Bewegung", "Test"];
        $maxImages = $this->ReadPropertyInteger("MaxArchiveImages");

        foreach ($archiveNames as $archiveName) {
            $categoryID = @IPS_GetObjectIDByIdent("Archive_" . $archiveName, $this->InstanceID);

            // Falls Kategorie nicht existiert, erstellen
            if ($categoryID === false) {
                $categoryID = IPS_CreateCategory();
                IPS_SetParent($categoryID, $this->InstanceID);
                IPS_SetIdent($categoryID, "Archive_" . $archiveName);
                IPS_SetName($categoryID, "Bildarchiv " . $archiveName);
            }

            // Bestehende Schnappschüsse ins Archiv kopieren
            $snapshotID = @IPS_GetObjectIDByIdent("Snapshot_" . $archiveName, $this->InstanceID);
            if ($snapshotID !== false) {
                $this->CopySnapshotToArchive($snapshotID, $categoryID, $maxImages);
            }
        }
    }

    private function RemoveArchives()
    {
        $archiveNames = ["Person", "Tier", "Fahrzeug", "Bewegung", "Test"];
        foreach ($archiveNames as $archiveName) {
            $categoryID = @IPS_GetObjectIDByIdent("Archive_" . $archiveName, $this->InstanceID);
            if ($categoryID !== false) {
                IPS_DeleteCategory($categoryID, true);
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

            copy($snapshotPath, $archiveFilePath);

            $mediaID = IPS_CreateMedia(1);
            IPS_SetParent($mediaID, $categoryID);
            IPS_SetName($mediaID, "Snapshot " . date("Y-m-d H:i:s"));
            IPS_SetMediaFile($mediaID, $archiveFilePath, false);

            $this->PruneArchive($categoryID, $maxImages);
        }
    }

    private function PruneArchive($categoryID, $maxImages)
    {
        $children = IPS_GetChildrenIDs($categoryID);
        if (count($children) > $maxImages) {
            $oldestID = $children[0];
            IPS_DeleteMedia($oldestID, true);
        }
    }

    private function RegisterHook()
{
    $baseHook = '/hook/reolink'; // Basisname des Webhooks
    $currentHook = $this->ReadAttributeString('CurrentHook');
    $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');

    if (count($ids) > 0) {
        $hookInstanceID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookInstanceID, 'Hooks'), true);

        if (!is_array($hooks)) {
            $hooks = [];
        }

        // Prüfen, ob der aktuelle Hook existiert
        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $currentHook && $hook['TargetID'] === $this->InstanceID) {
                $this->SendDebug('RegisterHook', "Aktueller Hook bereits registriert: $currentHook", 0);
                return; // Hook existiert bereits, keine Aktion nötig
            }
        }

        // Falls der aktuelle Hook fehlt, einen neuen erstellen
        $counter = 1;
        $hookName = $baseHook . '_' . $counter;

        do {
            $found = false;
            foreach ($hooks as $hook) {
                if ($hook['Hook'] === $hookName) {
                    $found = true;
                    $counter++;
                    $hookName = $baseHook . '_' . $counter;
                    break;
                }
            }
        } while ($found);

        // Neuen Hook hinzufügen
        $hooks[] = ['Hook' => $hookName, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookInstanceID);

        // Neuen Hook speichern
        $this->WriteAttributeString('CurrentHook', $hookName);
        $this->SendDebug('RegisterHook', "Neuer Hook registriert: $hookName", 0);
    }
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

?>
