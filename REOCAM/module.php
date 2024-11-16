<?php

class REOCAM extends IPSModule
{
    public function Create()
    {
        parent::Create();
        
        // Moduleigenschaften registrieren
        $this->RegisterPropertyString("CameraIP", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyString("StreamType", "sub");

        // Schalter zum Ein-/Ausblenden von Variablen und Schnappschüssen
        $this->RegisterPropertyBoolean("ShowWebhookVariables", false);
        $this->RegisterPropertyBoolean("ShowBooleanVariables", true);
        $this->RegisterPropertyBoolean("ShowSnapshots", true);
        $this->RegisterPropertyBoolean("ShowArchives", true);
        $this->RegisterPropertyBoolean("ShowTestElements", false);
        $this->RegisterPropertyInteger("MaxArchiveImages", 20);
        
        // Webhook registrieren
        $this->RegisterAttributeString("CurrentHook", "");

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
    
        // Sicherstellen, dass der Hook existiert
        $hookPath = $this->ReadAttributeString("CurrentHook");
        
    
        // Wenn der Hook-Pfad leer ist, initialisiere ihn
        if ($hookPath === "") {
            $hookPath = $this->RegisterHook();
            $this->SendDebug('ApplyChanges', "Die Initialisierung des Hook-Pfades '$hookPath' gestartet.", 0);
        }
    
        // Webhook-Pfad in der Form anzeigen
        $this->UpdateFormField("WebhookPath", "caption", "Webhook: " . $hookPath);
    
        // Verwalte Variablen und andere Einstellungen
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

        if ($this->ReadPropertyBoolean("ShowTestElements")) {
            $this->CreateTestElements();
        } else {
            $this->RemoveTestElements();
        }
        
        // Stream-URL aktualisieren
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");
    }

    private function RegisterHook()
    {

        $hookBase = '/hook/reolink_';
        $hookPath = $this->ReadAttributeString("CurrentHook");
    
        // Wenn kein Hook registriert ist, einen neuen erstellen
        if ($hookPath === "") {
            $hookPath = $hookBase . $this->InstanceID;
            $this->WriteAttributeString("CurrentHook", $hookPath);
        }
        
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) === 0) {
            $this->SendDebug('RegisterHook', 'Keine WebHook-Control-Instanz gefunden.', 0);
            return $hookPath;
        }
    
        $hookInstanceID = $ids[0];
        $hooks = json_decode(IPS_GetProperty($hookInstanceID, 'Hooks'), true);
    
        if (!is_array($hooks)) {
            $hooks = [];
        }
    
        /*

        // Prüfen, ob der Hook bereits existiert
        foreach ($hooks as $hook) {
            if ($hook['Hook'] === $hookPath && $hook['TargetID'] === $this->InstanceID) {
                $this->SendDebug('RegisterHook', "Hook '$hookPath' ist bereits registriert.", 0);
                return $hookPath;
            }
        }
    
        // Neuen Hook hinzufügen
        $hooks[] = ['Hook' => $hookPath, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($hookInstanceID);
        $this->SendDebug('RegisterHook', "Hook '$hookPath' wurde registriert.", 0);
        return $hookPath;
    }
        */

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
    
        // Webhook-Pfad dynamisch in das Konfigurationsformular einfügen
        $hookPath = $this->ReadAttributeString("CurrentHook");
        $webhookElement = [
            "type"    => "Label",
            "caption" => "Webhook: " . $hookPath
        ];
    
        // Einfügen an einer bestimmten Position, z. B. ganz oben oder nach einem spezifischen Element
        array_splice($form['elements'], 0, 0, [$webhookElement]); // Fügt es an Position 0 ein
    
        return json_encode($form);
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

    private function CreateBooleanVariables()
    {
        $this->RegisterVariableBoolean("Person", "Person", "~Motion", 20);
        $this->RegisterVariableBoolean("Tier", "Tier", "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug", "~Motion", 30);
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

    private function ActivateBoolean($ident, $position)
    {
        // Wenn Test-Elemente deaktiviert sind, keine Aktionen für "Test" ausführen
        if (!$this->ReadPropertyBoolean("ShowTestElements") && $ident === "Test") {
            $this->SendDebug('ActivateBoolean', "Aktion für Test übersprungen, da Test-Elemente deaktiviert sind.", 0);
            return;
        }
    
        $timerName = $ident . "_Reset";
    
        $this->SendDebug('ActivateBoolean', "Schalte Variable $ident auf true.", 0);
        $this->SetValue($ident, true);
    
        if ($this->ReadPropertyBoolean("ShowSnapshots")) {
            $this->CreateSnapshotAtPosition($ident, $position);
        }
    
        $this->SendDebug('ActivateBoolean', "Setze Timer für $timerName auf 5 Sekunden.", 0);
        $this->SetTimerInterval($timerName, 5000);
    }
    

    public function ResetBoolean(string $ident)
    {
        $timerName = $ident . "_Reset";

        // Debugging hinzufügen
        $this->SendDebug('ResetBoolean', "Setze Variable $ident auf false.", 0);

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

    private function CreateTestElements()
{
    // Test-Boolean-Variable
    $this->RegisterVariableBoolean("Test", "Test", "~Motion", 50);

    // Test-Snapshot
    if (!IPS_ObjectExists(@IPS_GetObjectIDByIdent("Snapshot_Test", $this->InstanceID))) {
        $mediaID = IPS_CreateMedia(1); // 1 = Bild
        IPS_SetParent($mediaID, $this->InstanceID);
        IPS_SetIdent($mediaID, "Snapshot_Test");
        IPS_SetName($mediaID, "Snapshot Test");
        IPS_SetMediaCached($mediaID, false);
    }

    // Test-Bildarchiv
    if (!IPS_ObjectExists(@IPS_GetObjectIDByIdent("Archive_Test", $this->InstanceID))) {
        $categoryID = IPS_CreateCategory();
        IPS_SetParent($categoryID, $this->InstanceID);
        IPS_SetIdent($categoryID, "Archive_Test");
        IPS_SetName($categoryID, "Bildarchiv Test");
    }
}

private function RemoveTestElements()
{
    // Entfernen der Test-Boolean-Variable
    $varID = @IPS_GetObjectIDByIdent("Test", $this->InstanceID);
    if ($varID) {
        $this->UnregisterVariable("Test");
    }

    // Entfernen des Test-Snapshots
    $mediaID = @IPS_GetObjectIDByIdent("Snapshot_Test", $this->InstanceID);
    if ($mediaID) {
        IPS_DeleteMedia($mediaID, true);
    }

    // Entfernen des Test-Bildarchivs
    $categoryID = @IPS_GetObjectIDByIdent("Archive_Test", $this->InstanceID);
    if ($categoryID) {
        $children = IPS_GetChildrenIDs($categoryID);
        foreach ($children as $childID) {
            IPS_DeleteMedia($childID, true);
        }
        IPS_DeleteCategory($categoryID);
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
        // Wenn Test-Elemente deaktiviert sind, keine Snapshots für "Test" erstellen
        if (!$this->ReadPropertyBoolean("ShowTestElements") && $booleanIdent === "Test") {
            $this->SendDebug('CreateSnapshotAtPosition', "Snapshot für Test übersprungen, da Test-Elemente deaktiviert sind.", 0);
            return;
        }
    
        $snapshotIdent = "Snapshot_" . $booleanIdent;
        $mediaID = @IPS_GetObjectIDByIdent($snapshotIdent, $this->InstanceID);
    
        // Neues Medienobjekt für den Schnappschuss erstellen, falls es nicht existiert
        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(1); // 1 = Bild
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $snapshotIdent);
            IPS_SetPosition($mediaID, $position);
            IPS_SetName($mediaID, "Snapshot von " . $booleanIdent);
            IPS_SetMediaCached($mediaID, false); // Kein Caching
    
            $this->SendDebug('CreateSnapshotAtPosition', "Neues Medienobjekt für Snapshot von $booleanIdent erstellt.", 0);
        } else {
            $this->SendDebug('CreateSnapshotAtPosition', "Vorhandenes Medienobjekt für Snapshot von $booleanIdent gefunden.", 0);
        }
    
        // Schnappschuss von der Kamera abrufen
        $snapshotUrl = $this->GetSnapshotURL();
        $tempImagePath = IPS_GetKernelDir() . "media/snapshot_temp_" . $booleanIdent . ".jpg";
        $imageData = @file_get_contents($snapshotUrl);
    
        if ($imageData !== false) {
            // Schnappschuss speichern
            file_put_contents($tempImagePath, $imageData);
            IPS_SetMediaFile($mediaID, $tempImagePath, false); // Medienobjekt mit Datei verbinden
            IPS_SendMediaEvent($mediaID); // Medienobjekt aktualisieren
    
            $this->SendDebug('CreateSnapshotAtPosition', "Snapshot für $booleanIdent erfolgreich erstellt.", 0);
    
            // Wenn Schnappschüsse aktiviert sind, auch ins Archiv kopieren
            if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                $archiveCategoryID = $this->CreateOrGetArchiveCategory($booleanIdent);
                $this->CreateArchiveSnapshot($booleanIdent, $archiveCategoryID); // Archivbild erstellen
            }
        } else {
            $this->SendDebug('CreateSnapshotAtPosition', "Fehler beim Abrufen des Snapshots für $booleanIdent.", 0);
            IPS_LogMessage("Reolink", "Snapshot konnte nicht abgerufen werden für $booleanIdent.");
        }
    }
    
    private function CreateOrGetArchiveCategory($booleanIdent)
    {
        $archiveIdent = "Archive_" . $booleanIdent;
        $categoryID = @IPS_GetObjectIDByIdent($archiveIdent, $this->InstanceID);
    
        if ($categoryID === false) {
            // Archivkategorie erstellen
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, $archiveIdent);
            IPS_SetName($categoryID, "Bildarchiv " . $booleanIdent);
    
            // Position basierend auf dem Boolean-Ident setzen
            switch ($booleanIdent) {
                case "Person":
                    IPS_SetPosition($categoryID, 22);
                    break;
                case "Tier":
                    IPS_SetPosition($categoryID, 27);
                    break;
                case "Fahrzeug":
                    IPS_SetPosition($categoryID, 32);
                    break;
                case "Bewegung":
                    IPS_SetPosition($categoryID, 37);
                    break;
                case "Test":
                    IPS_SetPosition($categoryID, 42);
                    break;
                default:
                    IPS_SetPosition($categoryID, 99); // Standardposition
                    break;
            }
        }
    
        return $categoryID;
    }
    
private function CreateOrUpdateArchives()
{
    // Boolean-Identifikatoren für die Archive
    $categories = ["Person", "Tier", "Fahrzeug", "Bewegung", "Test"];
    
    // Für jede Kategorie prüfen und aktualisieren
    foreach ($categories as $category) {
        // Archiv-Kategorie erstellen oder abrufen
        $categoryID = $this->CreateOrGetArchiveCategory($category);

        // Optional: Prune-Logik hier direkt anwenden
        $this->PruneArchive($categoryID); // Archivgröße sofort prüfen
    }
}

private function PruneArchive($categoryID)
{
    $maxImages = $this->ReadPropertyInteger("MaxArchiveImages"); // Max-Bilder aus Einstellungen
    $children = IPS_GetChildrenIDs($categoryID); // Bilder im Archiv abrufen

    // Debug-Ausgaben zur Überprüfung
    $this->SendDebug('PruneArchive', "Anzahl der Bilder im Archiv: " . count($children), 0);
    $this->SendDebug('PruneArchive', "Maximale Anzahl erlaubter Bilder: $maxImages", 0);

    if (count($children) > $maxImages) {
        // Sortiere die Bilder nach Position (höher = älter)
        usort($children, function ($a, $b) {
            $objectA = @IPS_GetObject($a); // Hole das Objekt sicher
            $objectB = @IPS_GetObject($b); // Hole das Objekt sicher
            if ($objectA === false || $objectB === false) {
                return 0; // Wenn eines der Objekte fehlt, bleibt die Reihenfolge unverändert
            }
            return $objectB['ObjectPosition'] <=> $objectA['ObjectPosition'];
        });

        // Entferne überschüssige Bilder
        while (count($children) > $maxImages) {
            $oldestID = array_shift($children); // Nimm das erste Element (höchste Position = ältestes)
            
            // Überprüfe, ob das Objekt existiert
            if (@IPS_ObjectExists($oldestID) && IPS_MediaExists($oldestID)) {
                IPS_DeleteMedia($oldestID, true); // Lösche das Medienobjekt
                $this->SendDebug('PruneArchive', "Entferntes Bild mit ID: $oldestID", 0);
            } else {
                $this->SendDebug('PruneArchive', "Bild mit ID $oldestID existiert nicht mehr, übersprungen.", 0);
            }
        }
    }
}

private function CreateArchiveSnapshot($booleanIdent, $categoryID)
{
    $archiveIdent = "Archive_" . $booleanIdent . "_" . time();
    $mediaID = IPS_CreateMedia(1); // Neues Medienobjekt für das Archiv-Bild
    IPS_SetParent($mediaID, $categoryID); // In der Archiv-Kategorie speichern
    IPS_SetIdent($mediaID, $archiveIdent);
    IPS_SetPosition($mediaID, -time()); // Negative Zeit für neueste zuerst
    IPS_SetName($mediaID, "" . $booleanIdent . " " . date("Y-m-d H:i:s"));
    IPS_SetMediaCached($mediaID, false); // Kein Caching

    $snapshotUrl = $this->GetSnapshotURL();
    $archiveImagePath = IPS_GetKernelDir() . "media/archive_temp_" . $booleanIdent . "_" . time() . ".jpg";
    $imageData = @file_get_contents($snapshotUrl);

    if ($imageData !== false) {
        file_put_contents($archiveImagePath, $imageData);
        IPS_SetMediaFile($mediaID, $archiveImagePath, false); // Datei dem Medienobjekt zuweisen
        IPS_SendMediaEvent($mediaID); // Aktualisieren des Medienobjekts

        $this->SendDebug('CreateArchiveSnapshot', "Archivbild für $booleanIdent erfolgreich erstellt.", 0);
        $this->PruneArchive($categoryID); // Maximale Anzahl der Bilder überprüfen
    } else {
        $this->SendDebug('CreateArchiveSnapshot', "Fehler beim Abrufen des Archivbilds für $booleanIdent.", 0);
        IPS_LogMessage("Reolink", "Archivbild konnte nicht abgerufen werden für $booleanIdent.");
    }
}

private function RemoveArchives()
{
    $categories = ["Person", "Tier", "Fahrzeug", "Bewegung", "Test"]; // Alle möglichen Archiv-Kategorien
    foreach ($categories as $category) {
        $archiveIdent = "Archive_" . $category;
        $categoryID = @IPS_GetObjectIDByIdent($archiveIdent, $this->InstanceID);
        if ($categoryID !== false) {
            $children = IPS_GetChildrenIDs($categoryID);
            foreach ($children as $childID) {
                if (IPS_MediaExists($childID)) {
                    IPS_DeleteMedia($childID, true); // Löscht das Medienobjekt
                }
            }
            IPS_DeleteCategory($categoryID); // Löscht die Kategorie
            $this->SendDebug('RemoveArchives', "Archivkategorie $categoryID wurde entfernt.", 0);
        }
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