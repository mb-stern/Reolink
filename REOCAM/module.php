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

        $this->RegisterPropertyBoolean("ShowMoveVariables", true);
        $this->RegisterPropertyBoolean("ShowSnapshots", true);
        $this->RegisterPropertyBoolean("ShowArchives", true);
        $this->RegisterPropertyBoolean("ShowTestElements", false);
        $this->RegisterPropertyBoolean("ShowVisitorElements", false);
        $this->RegisterPropertyBoolean("ApiFunktionen", true);
        $this->RegisterPropertyBoolean("EnablePolling", false);
        $this->RegisterPropertyInteger("PollingInterval", 2);
        $this->RegisterPropertyInteger("MaxArchiveImages", 20);
        
        $this->RegisterAttributeBoolean("ApiInitialized", false);
        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString("ApiToken", "");

        $this->RegisterTimer("Person_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Person");');
        $this->RegisterTimer("Tier_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Tier");');
        $this->RegisterTimer("Fahrzeug_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Fahrzeug");');
        $this->RegisterTimer("Bewegung_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Bewegung");');
        $this->RegisterTimer("Test_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Test");');
        $this->RegisterTimer("Besucher_Reset", 0, 'REOCAM_ResetMoveTimer($_IPS[\'TARGET\'], "Besucher");');
        $this->RegisterTimer("PollingTimer", 0, 'REOCAM_Polling($_IPS[\'TARGET\']);');
        $this->RegisterTimer("ApiRequestTimer", 0, 'REOCAM_ExecuteApiRequests($_IPS[\'TARGET\']);');
        $this->RegisterTimer("TokenRenewalTimer", 0, 'REOCAM_GetToken($_IPS[\'TARGET\']);');
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

        // Stream-URL aktualisieren
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");
    
        // Verwalte Variablen und andere Einstellungen
        if ($this->ReadPropertyBoolean("ShowMoveVariables")) {
            $this->CreateMoveVariables();
        } else {
            $this->RemoveMoveVariables();
        }
    
        if (!$this->ReadPropertyBoolean("ShowSnapshots")) {
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
        
        if ($this->ReadPropertyBoolean("ShowVisitorElements")) {
            $this->CreateVisitorElements();
        } else {
            $this->RemoveVisitorElements();
        }
        
        if ($this->ReadPropertyBoolean("EnablePolling")) {
            $interval = $this->ReadPropertyInteger("PollingInterval");
            $this->SetTimerInterval("PollingTimer", $interval * 1000);
        } else {
            $this->SetTimerInterval("PollingTimer", 0);
        }
        
        if ($this->ReadPropertyBoolean("ApiFunktionen")) {
            $this->SetTimerInterval("ApiRequestTimer", 10 * 1000); 
            $this->SetTimerInterval("TokenRenewalTimer", 3000 * 1000);
            $this->WriteAttributeBoolean("ApiInitialized", false);
            $this->CreateApiVariables();
            $this->GetToken();
            $this->ExecuteApiRequests();

        } else {
            $this->SetTimerInterval("ApiRequestTimer", 0);
            $this->SetTimerInterval("TokenRenewalTimer", 0);
            $this->RemoveApiVariables();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "WhiteLed":
                $this->SetWhiteLed($Value);
                SetValue($this->GetIDForIdent($Ident), $Value);
                break;
    
            case "Mode":
                $this->SetMode($Value);
                SetValue($this->GetIDForIdent($Ident), $Value);
                break;
    
            case "Bright":
                $this->SetBrightness($Value);
                SetValue($this->GetIDForIdent($Ident), $Value);
                break;
    
            default:
                throw new Exception("Invalid Ident");
        }
    }
        
    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
    
        // Webhook-Pfad dynamisch in das Konfigurationsformular einfügen
        $hookPath = $this->ReadAttributeString("CurrentHook");
        $webhookElement = [
            "type"    => "Label",
            "caption" => "Webhook: " . $hookPath
        ];
    
        array_splice($form['elements'], 0, 0, [$webhookElement]); // Fügt es an Position 0 ein
    
        return json_encode($form);
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
            $this->LogMessage("Reolink", "Keine Daten empfangen oder Datenstrom ist leer.");
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
                    if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                    $this->CreateSnapshotAtPosition("Person", 21);
                    }
                    $this->SetMoveTimer("Person");
                    break;
                
                case "ANIMAL":
                    if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                    $this->CreateSnapshotAtPosition("Tier", 26);
                    }
                    $this->SetMoveTimer("Tier");
                    break;
                
                case "VEHICLE":
                    if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                    $this->CreateSnapshotAtPosition("Fahrzeug", 31);
                    }
                    $this->SetMoveTimer("Fahrzeug");
                    break;
                
                case "MD":
                    if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                    $this->CreateSnapshotAtPosition("Bewegung", 36);
                    }
                    $this->SetMoveTimer("Bewegung");
                    break;
                
                case "VISITOR":
                    if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                    $this->CreateSnapshotAtPosition("Besucher", 41);
                }
                    $this->SetMoveTimer("Besucher");
                    break;    
                
                case "TEST":
                    if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                    $this->CreateSnapshotAtPosition("Test", 46);   
                }     
                    if ($this->ReadPropertyBoolean("ShowTestElements")) {
                    $this->SetMoveTimer("Test");  
                    }      
                    break;
            }
        }
    }

    private function SetMoveTimer($ident)
    {
        $timerName = $ident . "_Reset";
    
        $this->SendDebug('SetMoveTimer', "Setze Variable '$ident' auf true.", 0);
        $this->SetValue($ident, true);
    
        $this->SendDebug('SetMoveTimer', "Setze Timer für '$timerName' auf 5 Sekunden.", 0);
        $this->SetTimerInterval($timerName, 5000);
    }

    public function ResetMoveTimer(string $ident)
    {
        $timerName = $ident . "_Reset";

        // Debugging hinzufügen
        $this->SendDebug('ResetMoveTimer', "Setze Variable '$ident' auf false.", 0);

        $this->SetValue($ident, false);
        $this->SetTimerInterval($timerName, 0);
    }

    private function CreateMoveVariables()
    {
        $this->RegisterVariableBoolean("Person", "Person", "~Motion", 20);
        $this->RegisterVariableBoolean("Tier", "Tier", "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug", "~Motion", 30);
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion", 35);
        $this->RegisterVariableBoolean("Besucher", "Besucher", "~Motion", 40);
        $this->RegisterVariableBoolean("Test", "Test", "~Motion", 45);
    }

    private function RemoveMoveVariables()
    {
        $booleans = ["Person", "Tier", "Fahrzeug", "Bewegung", "Besucher", "Test"];
        foreach ($booleans as $booleanIdent) {
            $varID = @$this->GetIDForIdent($booleanIdent);
            if ($varID !== false) {
                $this->UnregisterVariable($booleanIdent);
            }
        }
    }

    private function CreateTestElements()
    {
        // Test-Boolean-Variable
        $this->RegisterVariableBoolean("Test", "Test", "~Motion", 50);

        // Test-Snapshot
        if (!IPS_ObjectExists(@$this->GetIDForIdent("Snapshot_Test"))) {
            $mediaID = IPS_CreateMedia(1); // 1 = Bild
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, "Snapshot_Test");
            IPS_SetName($mediaID, "Snapshot Test");
            IPS_SetMediaCached($mediaID, false);
        }

        // Test-Bildarchiv
        if (!IPS_ObjectExists(@$this->GetIDForIdent("Archive_Test"))) {
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, "Archive_Test");
            IPS_SetName($categoryID, "Bildarchiv Test");
        }
    }

    private function RemoveTestElements()
    {
        // Entfernen der Test-Boolean-Variable
        $varID = @$this->GetIDForIdent("Test");
        if ($varID) {
            $this->UnregisterVariable("Test");
        }

        // Entfernen des Test-Snapshots
        $mediaID = @$this->GetIDForIdent("Snapshot_Test");
        if ($mediaID) {
            IPS_DeleteMedia($mediaID, true);
        }

        // Entfernen des Test-Bildarchivs
        $categoryID = @$this->GetIDForIdent("Archive_Test");
        if ($categoryID) {
            $children = IPS_GetChildrenIDs($categoryID);
            foreach ($children as $childID) {
                IPS_DeleteMedia($childID, true);
            }
            IPS_DeleteCategory($categoryID);
        }
    }

    private function CreateVisitorElements()
    {
        // Besucher-Boolean-Variable
        $this->RegisterVariableBoolean("Besucher", "Besucher erkannt", "~Motion", 50);

        // Besucher-Snapshot
        if (!IPS_ObjectExists(@$this->GetIDForIdent("Snapshot_Besucher"))) {
            $mediaID = IPS_CreateMedia(1); // 1 = Bild
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, "Snapshot_Besucher");
            IPS_SetName($mediaID, "Snapshot Besucher");
            IPS_SetMediaCached($mediaID, false);
        }

        // Besucher-Bildarchiv
        if (!IPS_ObjectExists(@$this->GetIDForIdent("Archive_Besucher"))) {
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, "Archive_Besucher");
            IPS_SetName($categoryID, "Bildarchiv Besucher");
        }
    }

    private function RemoveVisitorElements()
    {
        // Entfernen der Besucher-Boolean-Variable
        $varID = @$this->GetIDForIdent("Besucher");
        if ($varID) {
            $this->UnregisterVariable("Besucher");
        }

        // Entfernen des Besucher-Snapshots
        $mediaID = @$this->GetIDForIdent("Snapshot_Besucher");
        if ($mediaID) {
            IPS_DeleteMedia($mediaID, true);
        }

        // Entfernen des Besucher-Bildarchivs
        $categoryID = @$this->GetIDForIdent("Archive_Besucher");
        if ($categoryID) {
            $children = IPS_GetChildrenIDs($categoryID);
            foreach ($children as $childID) {
                IPS_DeleteMedia($childID, true);
            }
            IPS_DeleteCategory($categoryID);
        }
    }

    private function CreateSnapshotAtPosition($booleanIdent, $position)
    {
        if (!$this->ReadPropertyBoolean("ShowTestElements") && $booleanIdent === "Test") {
            $this->SendDebug('CreateSnapshotAtPosition', "Snapshot für Test übersprungen, da Test-Elemente deaktiviert sind.", 0);
            return;
        }
        if (!$this->ReadPropertyBoolean("ShowVisitorElements") && $booleanIdent === "Besucher") {
            $this->SendDebug('CreateSnapshotAtPosition', "Snapshot für Besucher übersprungen, da Besucher-Elemente deaktiviert sind.", 0);
            return;
        }
    
        $snapshotIdent = "Snapshot_" . $booleanIdent;
        $mediaID = @$this->GetIDForIdent($snapshotIdent);
    
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
    
        $snapshotUrl = $this->GetSnapshotURL();
        $fileName = $booleanIdent . "_" . $mediaID . ".jpg";
        $filePath = IPS_GetKernelDir() . "media/" . $fileName;
        $imageData = @file_get_contents($snapshotUrl);
    
        if ($imageData !== false) {
            IPS_SetMediaFile($mediaID, $filePath, false); // Medienobjekt mit Datei verbinden
            IPS_SetMediaContent($mediaID,base64_encode($imageData));
            IPS_SendMediaEvent($mediaID); // Medienobjekt aktualisieren
    
            $this->SendDebug('CreateSnapshotAtPosition', "Snapshot für $booleanIdent erfolgreich erstellt mit Dateinamen: $fileName.", 0);
    
            if ($this->ReadPropertyBoolean("ShowSnapshots")) {
                $archiveCategoryID = $this->CreateOrGetArchiveCategory($booleanIdent);
                $this->CreateArchiveSnapshot($booleanIdent, $archiveCategoryID); // Archivbild erstellen
            }
        } else {
            $this->SendDebug('CreateSnapshotAtPosition', "Fehler beim Abrufen des Snapshots für $booleanIdent.", 0);
        }
    }

    private function RemoveSnapshots()
    {
        $snapshots = ["Snapshot_Person", "Snapshot_Tier", "Snapshot_Fahrzeug", "Snapshot_Test", "Snapshot_Besucher","Snapshot_Bewegung"];
        foreach ($snapshots as $snapshotIdent) {
            $mediaID = @$this->GetIDForIdent($snapshotIdent);
            if ($mediaID) {
                IPS_DeleteMedia($mediaID, true);
            }
        }
    }

    private function CreateOrUpdateArchives()
    {
        // Boolean-Identifikatoren für die Archive
        $categories = ["Person", "Tier", "Fahrzeug", "Bewegung", "Besucher", "Test"];
        
        // Für jede Kategorie prüfen und aktualisieren
        foreach ($categories as $category) {
            // Archiv-Kategorie erstellen oder abrufen
            $categoryID = $this->CreateOrGetArchiveCategory($category);
        }
    }
    
    private function CreateOrGetArchiveCategory($booleanIdent)
    {

        // Boolean-Identifikatoren für die Archive
        $categories = ["Person", "Tier", "Fahrzeug", "Bewegung", "Besucher", "Test"];
        
        // Für jede Kategorie prüfen und aktualisieren
        foreach ($categories as $category) {
            
            // Archiv-Kategorie erstellen oder abrufen
            $archiveIdent = "Archive_" . $booleanIdent;
            $categoryID = @$this->GetIDForIdent($archiveIdent);
        
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
                    case "Besucher":
                        IPS_SetPosition($categoryID, 42);
                        break;
                    case "Test":
                        IPS_SetPosition($categoryID, 47);
                        break;
                    default:
                        IPS_SetPosition($categoryID, 99); // Standardposition
                        break;
                }
            }
        }
    
        return $categoryID;
    }
    
    private function PruneArchive($categoryID, $booleanIdent)
    {
        $maxImages = $this->ReadPropertyInteger("MaxArchiveImages"); // Max-Bilder aus Einstellungen
        $children = IPS_GetChildrenIDs($categoryID); // Bilder im Archiv abrufen

        // Debug-Ausgaben zur Überprüfung
        $this->SendDebug('PruneArchive', "Anzahl der Bilder im Archiv '$booleanIdent': " . count($children), 0);
        $this->SendDebug('PruneArchive', "Maximale Anzahl erlaubter Bilder im Archiv '$booleanIdent': $maxImages", 0);

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
                    $this->SendDebug('PruneArchive', "Entferne das Bild mit der ID: $oldestID", 0);
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
        $archiveImagePath = IPS_GetKernelDir() . "media/" . $booleanIdent . "_" . $mediaID . ".jpg";
        $imageData = @file_get_contents($snapshotUrl);

        if ($imageData !== false) {
            IPS_SetMediaFile($mediaID, $archiveImagePath, false); // Datei dem Medienobjekt zuweisen
            IPS_SetMediaContent($mediaID,base64_encode($imageData));
            IPS_SendMediaEvent($mediaID); // Aktualisieren des Medienobjekts

            $this->SendDebug('CreateArchiveSnapshot', "Bild im Archiv '$booleanIdent' erfolgreich erstellt.", 0);
            $this->PruneArchive($categoryID, $booleanIdent); // Maximale Anzahl der Bilder überprüfen
        } else {
            $this->SendDebug('CreateArchiveSnapshot', "Fehler beim Abrufen des Archivbilds für '$booleanIdent'.", 0);
        }
    }

    private function RemoveArchives()
    {
        $categories = ["Person", "Tier", "Fahrzeug", "Bewegung", "Besucher", "Test"]; // Alle möglichen Archiv-Kategorien
        foreach ($categories as $category) {
            $archiveIdent = "Archive_" . $category;
            $categoryID = @$this->GetIDForIdent($archiveIdent);
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
        $mediaID = @$this->GetIDForIdent($ident);

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

    private function GetStreamURL()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));
        $streamType = $this->ReadPropertyString("StreamType");

        return $streamType === "main" ? 
               "rtsp://$username:$password@$cameraIP:554" :
               "rtsp://$username:$password@$cameraIP:554/h264Preview_01_sub";
    }

    private function GetSnapshotURL()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));

        return "http://$cameraIP/cgi-bin/api.cgi?cmd=Snap&user=$username&password=$password&width=1024&height=768";
    }

    public function GetToken()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");

        if (empty($cameraIP) || empty($username) || empty($password)) {
            $this->SendDebug("GetToken", "Die Moduleinstellungen sind unvollständig.", 0);
            return;
        }
        
    
        $url = "https://$cameraIP/api.cgi?cmd=Login";
        $data = [
            [
                "cmd" => "Login",
                "param" => [
                    "User" => [
                        "Version" => "0",
                        "userName" => $username,
                        "password" => $password
                    ]
                ]
            ]
        ];
    
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
        $response = curl_exec($ch);
    
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("cURL-Fehler: $error");
        }
    
        curl_close($ch);
        $this->SendDebug("GetToken", "Antwort: $response", 0);
    
        $responseData = json_decode($response, true);
        if (isset($responseData[0]['value']['Token']['name'])) {
            $token = $responseData[0]['value']['Token']['name'];
            $this->WriteAttributeString("ApiToken", $token);
            $this->SendDebug("GetToken", "Token erfolgreich gespeichert: $token", 0);
        } else {
            throw new Exception("Fehler beim Abrufen des Tokens: " . json_encode($responseData));
        }
    }    
    
    private function SetWhiteLed(bool $state)
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $token = $this->ReadAttributeString("ApiToken");

        // LED-Einstellungen setzen
        $url = "https://$cameraIP/api.cgi?cmd=SetWhiteLed&token=$token";
        $data = [
            [
                "cmd" => "SetWhiteLed",
                "param" => [
                    "WhiteLed" => [
                        "state" => $state ? 1 : 0, // 1 = Ein, 0 = Aus
                        "channel" => 0
                    ]
                ]
            ]
        ];

        $this->SendApiRequest($url, $data);
    }

    private function SetMode(int $mode)
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $token = $this->ReadAttributeString("ApiToken");

        $url = "https://$cameraIP/api.cgi?cmd=SetWhiteLed&token=$token";
        $data = [
            [
                "cmd" => "SetWhiteLed",
                "param" => [
                    "WhiteLed" => [
                        "mode" => $mode,
                        "channel" => 0
                    ]
                ]
            ]
        ];

        $this->SendApiRequest($url, $data);
    }

    private function SetBrightness(int $brightness)
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $token = $this->ReadAttributeString("ApiToken");

        $url = "https://$cameraIP/api.cgi?cmd=SetWhiteLed&token=$token";
        $data = [
            [
                "cmd" => "SetWhiteLed",
                "param" => [
                    "WhiteLed" => [
                        "bright" => $brightness,
                        "channel" => 0
                    ]
                ]
            ]
        ];

        $this->SendApiRequest($url, $data);
    }

    private function SendApiRequest(string $url, array $data)
    {
        // Anfrage-Daten debuggen
        $this->SendDebug("SendApiRequest", "URL: $url", 0);
        $this->SendDebug("SendApiRequest", "Daten: " . json_encode($data), 0);
    
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    
        $response = curl_exec($ch);
    
        // Fehler beim Abruf debuggen
        if ($response === false) {
            $error = curl_error($ch);
            $this->SendDebug("SendApiRequest", "cURL-Fehler: $error", 0);
            $this->LogMessage("Reolink: cURL-Fehler: $error", KL_ERROR);
            curl_close($ch);
            return null;
        }
    
        curl_close($ch);
    
        // Antwort debuggen
        $this->SendDebug("SendApiRequest", "Antwort: $response", 0);
    
        $responseData = json_decode($response, true);
    
        // Debug-Ausgabe für die decodierten Daten
        if ($responseData !== null) {
            $this->SendDebug("SendApiRequest", "Decoded Response: " . json_encode($responseData), 0);
        } else {
            $this->SendDebug("SendApiRequest", "Antwort konnte nicht decodiert werden.", 0);
        }
    
        // Prüfung der API-Antwort
        if (!isset($responseData[0]['code']) || $responseData[0]['code'] !== 0) {
            $this->SendDebug("SendApiRequest", "API-Befehl fehlgeschlagen: " . json_encode($responseData), 0);
            $this->LogMessage("Reolink: API-Befehl fehlgeschlagen: " . json_encode($responseData), KL_ERROR);
            return null;
        }
    
        // Erfolgreiche Antwort debuggen
        $this->SendDebug("SendApiRequest", "API-Befehl erfolgreich: " . json_encode($responseData), 0);
    
        return $responseData;
    }
    
    private function CreateApiVariables()
    {
        // White LED-Variable
        if (!@$this->GetIDForIdent("WhiteLed")) {
            $this->RegisterVariableBoolean("WhiteLed", "LED Status", "~Switch", 0);
            $this->EnableAction("WhiteLed");
        }
    
        // Mode-Variable
        if (!IPS_VariableProfileExists("REOCAM.WLED")) {
            IPS_CreateVariableProfile("REOCAM.WLED", 1); //1 für Integer
            IPS_SetVariableProfileValues("REOCAM.WLED", 0, 2, 1); //Min, Max, Schritt
            IPS_SetVariableProfileDigits("REOCAM.WLED", 0); //Nachkommastellen
            IPS_SetVariableProfileAssociation("REOCAM.WLED", 0, "Aus", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.WLED", 1, "Automatisch", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.WLED", 2, "Zeitabhängig", "", -1);
        }

        if (!@$this->GetIDForIdent("Mode")) {
            $this->SendDebug("CreateApiVariables", "Variablenprofil REOCAM.WLED erstellt", 0);
            $this->RegisterVariableInteger("Mode", "LED Modus", "REOCAM.WLED", 1);
            $this->EnableAction("Mode");
        }
    
        // Bright-Variable
        if (!@$this->GetIDForIdent("Bright")) {
            $this->RegisterVariableInteger("Bright", "LED Helligkeit", "~Intensity.100", 2);
            $this->EnableAction("Bright");
        }
    }
    
    private function RemoveApiVariables()
    {
        // White LED-Variable entfernen
        $varID = @$this->GetIDForIdent("WhiteLed");
        if ($varID) {
            $this->UnregisterVariable("WhiteLed");
        }
    
        // Mode-Variable entfernen
        $varID = @$this->GetIDForIdent("Mode");
        if ($varID) {
            $this->UnregisterVariable("Mode");
        }
    
        // Bright-Variable entfernen
        $varID = @$this->GetIDForIdent("Bright");
        if ($varID) {
            $this->UnregisterVariable("Bright");
        }
    }
    
    public function Polling()
    {
        if (!$this->ReadPropertyBoolean("EnablePolling")) {
            $this->SetTimerInterval("PollingTimer", 0);
            return;
        }

        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = urlencode($this->ReadPropertyString("Username"));
        $password = urlencode($this->ReadPropertyString("Password"));

        $url = "http://$cameraIP/cgi-bin/api.cgi?cmd=GetAiState&rs=&user=$username&password=$password";

        $response = @file_get_contents($url);
        if ($response === false) {
            $this->SendDebug("Polling", "Fehler beim Abrufen der Daten von der Kamera.", 0);
            return;
        }

        $this->SendDebug("Polling", "Rohdaten: $response", 0);

        $data = json_decode($response, true);
        if ($data === null || !isset($data[0]['value'])) {
            $this->SendDebug("Polling", "Ungültige Daten empfangen: $response", 0);
            return;
        }

        $aiState = $data[0]['value'];

        $this->PollingUpdateState("dog_cat", $aiState['dog_cat']['alarm_state'] ?? 0);
        $this->PollingUpdateState("people", $aiState['people']['alarm_state'] ?? 0);
        $this->PollingUpdateState("vehicle", $aiState['vehicle']['alarm_state'] ?? 0);
    }

    private function PollingUpdateState(string $type, int $state)
    {
        // Mapping der AI-Typen zu den Variablen
        $mapping = [
            "dog_cat" => "Tier",
            "people"  => "Person",
            "vehicle" => "Fahrzeug"
        ];

        if (!isset($mapping[$type])) {
            $this->SendDebug("PollingUpdateState", "Unbekannter Typ: $type", 0);
            return;
        }

        $ident = $mapping[$type];
        $variableID = @$this->GetIDForIdent($ident);

        if ($variableID !== false) {
            $currentValue = GetValue($variableID);

            // Aktualisiere die Variable nur, wenn sich der Zustand geändert hat
            if ($currentValue != ($state == 1)) {
                $this->SetValue($ident, $state == 1);
                $this->SendDebug("PollingUpdateState", "Variable '$ident' auf " . ($state == 1 ? "true" : "false") . " gesetzt.", 0);

                // Timer setzen, um die Variable nach 5 Sekunden zurückzusetzen
                $timerName = $ident . "_Reset";
                if ($state == 1) {
                    $this->SetTimerInterval($timerName, 5000);
                    // Schnappschuss auslösen
                    $this->SendDebug("PollingUpdateState", "Löse Schnappschuss für '$ident' aus.", 0);
                    $this->CreateSnapshotAtPosition($ident, IPS_GetObject($variableID)['ObjectPosition'] + 1);
                } else {
                    $this->SetTimerInterval($timerName, 0); // Timer stoppen
                }
            } else {
                $this->SendDebug("PollingUpdateState", "Keine Änderung für '$ident', kein Schnappschuss ausgelöst.", 0);
            }
        } else {
            $this->SendDebug("PollingUpdateState", "Variable '$ident' nicht gefunden.", 0);
        }
    }

    public function ExecuteApiRequests()
    {
        $this->SendDebug("ExecuteApiRequests", "Starte API-Abfragen...", 0);

        // API-Funktion: GetWhiteLed
        $this->UpdateWhiteLedStatus();

        // Weitere API-Funktionen können hier hinzugefügt werden
    }

    private function UpdateWhiteLedStatus()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $token = $this->ReadAttributeString("ApiToken");
    
        $url = "https://$cameraIP/api.cgi?cmd=GetWhiteLed&token=$token";
        $data = json_encode([
            [
                "cmd" => "GetWhiteLed",
                "action" => 0,
                "param" => [
                    "channel" => 0
                ]
            ]
        ]);
    
        $this->SendDebug("UpdateWhiteLedStatus", "Anfrage-URL: $url", 0);
    
        // cURL-Setup
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    
        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->SendDebug("UpdateWhiteLedStatus", "cURL-Fehler: $error", 0);
            return;
        }
        curl_close($ch);
    
        $this->SendDebug("UpdateWhiteLedStatus", "Antwort: $response", 0);
    
        $responseData = json_decode($response, true);
        if ($responseData === null || !isset($responseData[0]['value']['WhiteLed'])) {
            $this->SendDebug("UpdateWhiteLedStatus", "Ungültige Antwort oder fehlende 'WhiteLed'-Daten", 0);
            return;
        }
    
        $whiteLedData = $responseData[0]['value']['WhiteLed'];
    
        // Prüfen, ob Variablen initialisiert wurden
        $initialized = $this->ReadAttributeBoolean("ApiInitialized");
    
        // Mapping JSON -> Variablen
        $mapping = [
            'state'  => 'WhiteLed',
            'mode'   => 'Mode',
            'bright' => 'Bright'
        ];
    
        // Aktualisiere Variablen
        foreach ($mapping as $jsonKey => $variableIdent) {
            if (isset($whiteLedData[$jsonKey])) {
                $newValue = $whiteLedData[$jsonKey];
                $variableID = @$this->GetIDForIdent($variableIdent);
    
                if ($variableID !== false) {
                    $currentValue = GetValue($variableID);
    
                    // Typkonvertierung für boolesche Werte
                    if (is_bool($currentValue)) {
                        $newValue = (bool)$newValue;
                    }
    
                    // Initialisieren oder aktualisieren
                    if (!$initialized || $currentValue !== $newValue) {
                        $this->SetValue($variableIdent, $newValue);
                        $this->SendDebug("UpdateWhiteLedStatus", "Variable '$variableIdent' aktualisiert: $currentValue -> $newValue", 0);
                    } else {
                        $this->SendDebug("UpdateWhiteLedStatus", "Keine Änderung für '$variableIdent' ($currentValue)", 0);
                    }
                } else {
                    $this->SendDebug("UpdateWhiteLedStatus", "Variable '$variableIdent' existiert nicht", 0);
                }
            } else {
                $this->SendDebug("UpdateWhiteLedStatus", "Key '$jsonKey' nicht in der Antwort vorhanden", 0);
            }
        }
    
        // Initialisierung abschließen
        if (!$initialized) {
            $this->WriteAttributeBoolean("ApiInitialized", true);
            $this->SendDebug("UpdateWhiteLedStatus", "Variablen initialisiert", 0);
        }
    }
}
