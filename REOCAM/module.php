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
        $this->RegisterAttributeBoolean("TokenRefreshing", false);
        $this->RegisterAttributeBoolean("HasPTZ", false);
        $this->RegisterAttributeInteger("ApiTokenExpiresAt", 0);
        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString("ApiToken", "");
        $this->RegisterAttributeString("EmailApiVersion", ""); // "V20" oder "LEGACY"


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
            $this->SetTimerInterval("TokenRenewalTimer", 3300 * 1000);
            $this->WriteAttributeBoolean("ApiInitialized", false);
            $this->CreateApiVariables();
            $this->GetToken();
            $this->ExecuteApiRequests();

        } else {
            $this->SetTimerInterval("ApiRequestTimer", 0);
            $this->SetTimerInterval("TokenRenewalTimer", 0);
            $this->RemoveApiVariables();
        }

        $this->CheckAndCreatePTZUI();
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case "WhiteLed":
                $ok = $this->SetWhiteLed((bool)$Value);
                if ($ok) {
                    SetValue($this->GetIDForIdent($Ident), (bool)$Value);
                } else {
                    $this->UpdateWhiteLedStatus(); // zurücklesen
                }
                break;

            case "Mode":
                $ok = $this->SetMode((int)$Value);
                if ($ok) {
                    SetValue($this->GetIDForIdent($Ident), (int)$Value);
                } else {
                    $this->UpdateWhiteLedStatus();
                }
                break;

            case "Bright":
                $ok = $this->SetBrightness((int)$Value);
                if ($ok) {
                    SetValue($this->GetIDForIdent($Ident), (int)$Value);
                } else {
                    $this->UpdateWhiteLedStatus();
                }
                break;

            case "EmailNotify":
                $ok = $this->SetEmailEnabled((bool)$Value);
                if ($ok) {
                    SetValue($this->GetIDForIdent($Ident), (bool)$Value);
                } else {
                    $this->UpdateEmailStatusVar();
                }
                break;

            case "EmailInterval":
                $ok = $this->SetEmailInterval((int)$Value);
                if ($ok) {
                    SetValue($this->GetIDForIdent($Ident), (int)$Value);
                } else {
                    $this->UpdateEmailVars(); // zurücklesen
                }
                break;

            case "EmailContent":
                $ok = $this->SetEmailContent((int)$Value);
                if ($ok) {
                    SetValue($this->GetIDForIdent($Ident), (int)$Value);
                } else {
                    $this->UpdateEmailVars();
                }
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
        $this->SendDebug('Webhook', 'triggered', 0);

        // 1) Versuch: JSON-Body
        $ptz = null;
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data) && isset($data['ptz'])) {
                $ptz = (string)$data['ptz'];
            }
        }

        // 2) Fallback: POST- oder GET-Parameter
        if ($ptz === null) {
            if (isset($_POST['ptz'])) {
                $ptz = (string)$_POST['ptz'];
            } elseif (isset($_GET['ptz'])) {
                $ptz = (string)$_GET['ptz'];
            }
        }

        // 3) PTZ-Steuerung?
        if ($ptz !== null) {
            $this->SendDebug('Webhook', 'PTZ='.$ptz, 0);
            $this->HandlePtzCommand($ptz);
            header('Content-Type: text/plain; charset=utf-8');
            echo "OK";
            return;
        }

        // 4) Bestehende Alarm-Verarbeitung (nur wenn sinnvolles JSON vorlag)
        if ($raw !== false && $raw !== '') {
            $data = json_decode($raw, true);
            if (is_array($data)) {
                $this->ProcessAllData($data);
                header('Content-Type: text/plain; charset=utf-8');
                echo "OK";
                return;
            }
        }

        $this->SendDebug('Webhook', 'No PTZ / no usable payload', 0);
        header('HTTP/1.1 400 Bad Request');
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERROR";
    }

    private function ProcessAllData($data)
    {
        if (isset($data['alarm']['type'])) {
            $type = $data['alarm']['type'];

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
        foreach (["Person","Tier","Fahrzeug","Bewegung","Besucher","Test"] as $cat) {
            $this->CreateOrGetArchiveCategory($cat);
        }
    }

    private function CreateOrGetArchiveCategory(string $booleanIdent)
    {
        $archiveIdent = "Archive_" . $booleanIdent;
        $categoryID = @$this->GetIDForIdent($archiveIdent);

        if ($categoryID === false) {
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, $archiveIdent);
            IPS_SetName($categoryID, "Bildarchiv " . $booleanIdent);

            // Positionen konsistent setzen
            $pos = [
                "Person"   => 22,
                "Tier"     => 27,
                "Fahrzeug" => 32,
                "Bewegung" => 37,
                "Besucher" => 42,
                "Test"     => 47
            ][$booleanIdent] ?? 99;

            IPS_SetPosition($categoryID, $pos);
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
    
    // === ZENTRALE API-HELFER ===

    private function apiBase(): string {
        $ip = $this->ReadPropertyString("CameraIP");
        return "http://{$ip}/api.cgi";
    }

    private function apiHttpPostJson(string $url, array $payload, bool $suppressError=false): ?array {
        $this->SendDebug("HTTP", "POST $url :: ".json_encode($payload), 0);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload)
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            if (!$suppressError) {
                $this->SendDebug("HTTP", "cURL error: $err", 0);
                $this->LogMessage("Reolink: cURL-Fehler: $err", KL_ERROR);
            }
            return null;
        }
        curl_close($ch);
        $this->SendDebug("HTTP", "RAW ".$raw, 0);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }

    private function apiEnsureToken(): bool {
        $token = $this->ReadAttributeString("ApiToken");
        $exp   = (int)$this->ReadAttributeInteger("ApiTokenExpiresAt");
        if ($token === "" || time() >= ($exp - 30)) {
            $this->GetToken();
        }
        return $this->ReadAttributeString("ApiToken") !== "";
    }

    /**
     * Generischer API-Call mit Token + Auto-Retry bei rspCode -6.
     * Beispiel:
     *   $this->apiCall([[ "cmd"=>"GetWhiteLed", "action"=>0, "param"=>["channel"=>0] ]]);
     */
    private function apiCall(array $cmdPayload, bool $suppressError=false): ?array {
        if (!$this->apiEnsureToken()) return null;

        $token = $this->ReadAttributeString("ApiToken");
        $url   = $this->apiBase() . "?token={$token}";

        $resp  = $this->apiHttpPostJson($url, $cmdPayload, $suppressError);
        if (!$resp) return null;

        if (isset($resp[0]['code']) && (int)$resp[0]['code'] === 0) {
            return $resp;
        }

        $rsp = $resp[0]['error']['rspCode'] ?? null;
        if ((int)$rsp === -6) {
            $this->SendDebug("API", "Auth -6 -> Token Refresh + Retry", 0);
            $this->GetToken();
            $token2 = $this->ReadAttributeString("ApiToken");
            if ($token2) {
                $url2  = $this->apiBase() . "?token={$token2}";
                $resp2 = $this->apiHttpPostJson($url2, $cmdPayload, $suppressError);
                if (is_array($resp2) && isset($resp2[0]['code']) && (int)$resp2[0]['code'] === 0) {
                    return $resp2;
                }
                // Falls wieder Fehler: unten in den generischen Fehlerpfad fallen
                $resp = $resp2;
            }
        }

        if (!$suppressError) {
            $this->SendDebug("API", "FAIL ".json_encode($resp), 0);
            $this->LogMessage("Reolink: API-Befehl fehlgeschlagen: ".json_encode($resp), KL_ERROR);
        }
        return null;
    }
    
    // ab hier API-Funktionen

    public function GetToken()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");

        if (empty($cameraIP) || empty($username) || empty($password)) {
            $this->SendDebug("GetToken", "Die Moduleinstellungen sind unvollständig.", 0);
            return;
        }

        $sem = "REOCAM_{$this->InstanceID}_GetToken";
        $entered = function_exists('IPS_SemaphoreEnter') ? IPS_SemaphoreEnter($sem, 5000) : true;
        if (!$entered) {
            $this->SendDebug("GetToken", "Anderer Login läuft – übersprungen.", 0);
            return;
        }
        $this->WriteAttributeBoolean("TokenRefreshing", true);

        try {
            $url = "http://$cameraIP/api.cgi?cmd=Login";
            $data = [[
                "cmd"   => "Login",
                "param" => ["User" => ["Version"=>"0","userName"=>$username,"password"=>$password]]
            ]];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch); curl_close($ch);
                throw new Exception("cURL-Fehler: $err");
            }
            curl_close($ch);

            $this->SendDebug("GetToken", "Antwort: $response", 0);
            $responseData = json_decode($response, true);

            if (isset($responseData[0]['value']['Token']['name'])) {
                $token = $responseData[0]['value']['Token']['name'];
                $this->WriteAttributeString("ApiToken", $token);
                $this->WriteAttributeInteger("ApiTokenExpiresAt", time() + 3600 - 5); // ~1h mit Puffer
                // proaktives Erneuern: ~50min
                $this->SetTimerInterval("TokenRenewalTimer", 3000 * 1000);
                $this->SendDebug("GetToken", "Token gespeichert & Ablauf gesetzt.", 0);
            } else {
                throw new Exception("Fehler beim Abrufen des Tokens: ".json_encode($responseData));
            }
        } finally {
            $this->WriteAttributeBoolean("TokenRefreshing", false);
            if (function_exists('IPS_SemaphoreLeave')) IPS_SemaphoreLeave($sem);
        }
    }
    
    private function SendLedRequest(array $ledParams): bool
    {
        $payload = [[
            "cmd"   => "SetWhiteLed",
            "param" => [ "WhiteLed" => array_merge($ledParams, ["channel" => 0]) ]
        ]];
        $res = $this->apiCall($payload);
        return is_array($res) && isset($res[0]['code']) && $res[0]['code'] === 0;
    }

    private function SetWhiteLed(bool $state): bool { return $this->SendLedRequest(['state' => $state ? 1 : 0]); }
    
    private function SetMode(int $mode): bool      { return $this->SendLedRequest(['mode'  => $mode]); }
    
    private function SetBrightness(int $b): bool   { return $this->SendLedRequest(['bright'=> $b]); }

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
            $this->RegisterVariableInteger("Mode", "LED Modus", "REOCAM.WLED", 1);
            $this->EnableAction("Mode");
        }
    
        // Bright-Variable
        if (!@$this->GetIDForIdent("Bright")) {
            $this->RegisterVariableInteger("Bright", "LED Helligkeit", "~Intensity.100", 2);
            $this->EnableAction("Bright");
        }

        // E-Mail Versand schalten
        if (!@$this->GetIDForIdent("EmailNotify")) {
            $this->RegisterVariableBoolean("EmailNotify", "E-Mail Versand", "~Switch", 3);
            $this->EnableAction("EmailNotify");
        }

        if (!IPS_VariableProfileExists("REOCAM.EmailInterval")) {
            IPS_CreateVariableProfile("REOCAM.EmailInterval", 1); // Integer
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 30,   "30 Sek.",    "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 60,   "1 Minute",   "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 300,  "5 Minuten",  "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 600,  "10 Minuten", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 1800, "30 Minuten", "", -1);
        } 

        if (!@$this->GetIDForIdent("EmailInterval")) {
        $this->RegisterVariableInteger("EmailInterval", "E-Mail Intervall", "REOCAM.EmailInterval", 4);
        $this->EnableAction("EmailInterval");
        }

        // Profil für E-Mail-Inhalt
        if (!IPS_VariableProfileExists("REOCAM.EmailContent")) {
            IPS_CreateVariableProfile("REOCAM.EmailContent", 1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 0, "Text", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 1, "Bild (ohne Text)", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 2, "Text + Bild", "", -1);
            IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 3, "Text + Video", "", -1);
        }
        if (!@$this->GetIDForIdent("EmailContent")) {
            $this->RegisterVariableInteger("EmailContent", "E-Mail Inhalt", "REOCAM.EmailContent", 5);
            $this->EnableAction("EmailContent");
        }
    }

    private function RemoveApiVariables()
    {
        // Standard-API-Variablen
        $idents = ["WhiteLed", "Mode", "Bright", "EmailNotify", "EmailInterval", "EmailContent"];
        foreach ($idents as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false) {
                $this->UnregisterVariable($ident);
            }
        }

        $this->RemovePTZUI();
    }

        private function RemovePTZUI(): void
    {
        $id = @$this->GetIDForIdent("PTZ_HTML");
        if ($id !== false) {
            $this->UnregisterVariable("PTZ_HTML");
        }
    }

    public function ExecuteApiRequests()
    {
        if (!$this->apiEnsureToken()) {
            $this->SendDebug("ExecuteApiRequests", "Kein gültiger Token – Abfragen übersprungen.", 0);
            return;
        }
        $this->SendDebug("ExecuteApiRequests", "Starte API-Abfragen...", 0);

        $this->UpdateWhiteLedStatus();
        $this->UpdateEmailVars();
        $this->CheckAndCreatePTZUI();
    }

    private function UpdateWhiteLedStatus()
    {
        $resp = $this->apiCall([[ "cmd"=>"GetWhiteLed", "action"=>0, "param"=>["channel"=>0] ]]);
        if (!$resp || !isset($resp[0]['value']['WhiteLed'])) {
            $this->SendDebug("UpdateWhiteLedStatus", "Ungültige Antwort", 0);
            return;
        }

        $whiteLedData = $resp[0]['value']['WhiteLed'];
        $initialized  = $this->ReadAttributeBoolean("ApiInitialized");

        $mapping = ['state'=>'WhiteLed','mode'=>'Mode','bright'=>'Bright'];
        foreach ($mapping as $jsonKey => $variableIdent) {
            if (!array_key_exists($jsonKey, $whiteLedData)) continue;
            $newValue = $whiteLedData[$jsonKey];
            $variableID = @$this->GetIDForIdent($variableIdent);
            if ($variableID === false) continue;

            $currentValue = GetValue($variableID);
            if (is_bool($currentValue)) $newValue = (bool)$newValue;

            if (!$initialized || $currentValue !== $newValue) {
                $this->SetValue($variableIdent, $newValue);
                $this->SendDebug("UpdateWhiteLedStatus", "Variable '$variableIdent' aktualisiert.", 0);
            }
        }

        if (!$initialized) {
            $this->WriteAttributeBoolean("ApiInitialized", true);
            $this->SendDebug("UpdateWhiteLedStatus", "Variablen initialisiert", 0);
        }
    }

    private function DetectEmailApiVersion(): string
    {
        $cached = $this->ReadAttributeString("EmailApiVersion");
        if ($cached === "V20" || $cached === "LEGACY") return $cached;

        $test = $this->apiCall([[ "cmd"=>"GetEmailV20", "param"=>["channel"=>0] ]], /*suppressError*/ true);
        $ver  = (is_array($test) && isset($test[0]['code']) && $test[0]['code']===0) ? "V20" : "LEGACY";
        $this->WriteAttributeString("EmailApiVersion", $ver);
        return $ver;
    }

    private function GetEmailEnabled(): ?bool
    {
        $apiVer = $this->DetectEmailApiVersion();

        if ($apiVer === 'V20') {
            $res = $this->apiCall([[ "cmd"=>"GetEmailV20", "param"=>["channel"=>0] ]]);
            if (is_array($res) && isset($res[0]['value']['Email']['enable'])) {
                return (bool)$res[0]['value']['Email']['enable'];
            }
        } else {
            $res = $this->apiCall([[ "cmd"=>"GetEmail", "param"=>["channel"=>0] ]]);
            if (is_array($res) && isset($res[0]['value']['Email']['schedule']['enable'])) {
                return (bool)$res[0]['value']['Email']['schedule']['enable'];
            }
        }
        $this->SendDebug("GetEmailEnabled", "Konnte Status nicht ermitteln.", 0);
        return null;
    }

    private function SetEmailEnabled(bool $enable): bool
    {
        $apiVer = $this->DetectEmailApiVersion();

        if ($apiVer === 'V20') {
            $res = $this->apiCall([[
                "cmd"   => "SetEmailV20",
                "param" => [ "Email" => [ "enable" => $enable ? 1 : 0 ] ]
            ]]);
            $ok = is_array($res) && isset($res[0]['code']) && $res[0]['code'] === 0;
            if (!$ok) $this->SendDebug("SetEmailEnabled", "Fehlgeschlagen: ".json_encode($res), 0);
            return $ok;
        } else {
            $res = $this->apiCall([[
                "cmd"   => "SetEmail",
                "param" => [ "Email" => [ "schedule" => [ "enable" => $enable ? 1 : 0 ] ] ]
            ]]);
            $ok = is_array($res) && isset($res[0]['code']) && $res[0]['code'] === 0;
            if (!$ok) $this->SendDebug("SetEmailEnabled", "Fehlgeschlagen: ".json_encode($res), 0);
            return $ok;
        }
    }

    private function UpdateEmailStatusVar(): void
    {
        $id = @$this->GetIDForIdent("EmailNotify");
        if ($id === false) {
            return; // Variable existiert nicht (Feature deaktiviert?)
        }
        $val = $this->GetEmailEnabled();
        if ($val !== null) {
            $this->SetValue("EmailNotify", $val);
        }
    }

    private function IntervalSecondsToString(int $sec): ?string {
        switch ($sec) {
            case 30:   return "30 Seconds";
            case 60:   return "1 Minute";
            case 300:  return "5 Minutes";
            case 600:  return "10 Minutes";
            case 1800: return "30 Minutes";
        }
        return null;
    }

    private function IntervalStringToSeconds(string $s): ?int {
        $s = trim($s);
        $map = [
            "30 Seconds" => 30,
            "1 Minute"   => 60,
            "5 Minutes"  => 300,
            "10 Minutes" => 600,
            "30 Minutes" => 1800
        ];
        return $map[$s] ?? null;
    }

    private function GetEmailInterval(): ?int 
    {
        $apiVer = $this->DetectEmailApiVersion();
        $res = ($apiVer === 'V20')
            ? $this->apiCall([[ "cmd" => "GetEmailV20", "param" => ["channel" => 0] ]])
            : $this->apiCall([[ "cmd" => "GetEmail",    "param" => ["channel" => 0] ]]);

        if (is_array($res) && isset($res[0]['value']['Email'])) {
            $email = $res[0]['value']['Email'];
            if (isset($email['intervalSec']) && is_numeric($email['intervalSec'])) return (int)$email['intervalSec'];
            if (isset($email['interval'])) {
                $sec = $this->IntervalStringToSeconds((string)$email['interval']);
                if ($sec !== null) return $sec;
            }
        }
        $this->SendDebug("GetEmailInterval", "Intervall unbekannt: ".json_encode($res), 0);
        return null;
    }

    private function SetEmailInterval(int $sec): bool 
    {
        $str = $this->IntervalSecondsToString($sec);
        if ($str === null) {
            $this->SendDebug("SetEmailInterval", "Ungültiger Sekundenwert: $sec", 0);
            return false;
        }

        $apiVer = $this->DetectEmailApiVersion();
        $res = ($apiVer === 'V20')
            ? $this->apiCall([[ "cmd"=>"SetEmailV20", "param"=> [ "Email" => [ "interval" => $str ] ] ]])
            : $this->apiCall([[ "cmd"=>"SetEmail",    "param"=> [ "Email" => [ "interval" => $str ] ] ]]);

        $ok  = is_array($res) && isset($res[0]['code']) && $res[0]['code'] === 0;
        if (!$ok) $this->SendDebug("SetEmailInterval", "Fehlgeschlagen für '$str': " . json_encode($res), 0);
        return $ok;
    }

    private function UpdateEmailVars(): void
    {
        // EmailNotify (bool)
        $id = @$this->GetIDForIdent("EmailNotify");
        if ($id !== false) {
            $new = $this->GetEmailEnabled();
            if ($new !== null) {
                $old = GetValue($id);
                $new = (bool)$new;
                if ($old !== $new) {
                    $this->SetValue("EmailNotify", $new);
                    $this->SendDebug("UpdateEmailVars", "EmailNotify: $old -> $new", 0);
                } else {
                    $this->SendDebug("UpdateEmailVars", "EmailNotify unverändert ($old)", 0);
                }
            }
        }

        // EmailInterval (int, Sekunden)
        $id = @$this->GetIDForIdent("EmailInterval");
        if ($id !== false) {
            $new = $this->GetEmailInterval();
            if ($new !== null) {
                $old = GetValue($id);
                $new = (int)$new;
                if ($old !== $new) {
                    $this->SetValue("EmailInterval", $new);
                    $this->SendDebug("UpdateEmailVars", "EmailInterval: $old -> $new", 0);
                } else {
                    $this->SendDebug("UpdateEmailVars", "EmailInterval unverändert ($old)", 0);
                }
            }
        }

        // EmailContent (int: 0=Text, 1=Bild, 2=Text+Bild, 3=Text+Video)
        $id = @$this->GetIDForIdent("EmailContent");
        if ($id !== false) {
            $new = $this->GetEmailContent();
            if ($new !== null) {
                $old = GetValue($id);
                $new = (int)$new;
                if ($old !== $new) {
                    $this->SetValue("EmailContent", $new);
                    $this->SendDebug("UpdateEmailVars", "EmailContent: $old -> $new", 0);
                } else {
                    $this->SendDebug("UpdateEmailVars", "EmailContent unverändert ($old)", 0);
                }
            }
        }
    }

    private function GetEmailContent(): ?int 
    {
        $apiVer = $this->DetectEmailApiVersion();

        if ($apiVer === 'V20') {
            $res  = $this->apiCall([[ "cmd" => "GetEmailV20", "param" => ["channel" => 0] ]]);
            if (is_array($res) && isset($res[0]['value']['Email'])) {
                $e    = $res[0]['value']['Email'];
                $text = isset($e['textType']) ? (int)$e['textType'] : 1;
                $att  = isset($e['attachmentType']) ? (int)$e['attachmentType'] : 0;

                if (!$text && $att === 1) return 1; // nur Bild
                if ( $text && $att === 0) return 0; // nur Text
                if ( $text && $att === 1) return 2; // Text + Bild
                if ( $text && $att === 2) return 3; // Text + Video
                return 0;
            }
        } else {
            $res  = $this->apiCall([[ "cmd" => "GetEmail", "param" => ["channel" => 0] ]]);
            if (is_array($res) && isset($res[0]['value']['Email']['attachment'])) {
                switch ($res[0]['value']['Email']['attachment']) {
                    case 'onlyPicture': return 1;
                    case 'picture':     return 2;
                    case 'video':       return 3;
                    default:            return 0;
                }
            }
            return 0;
        }
        return null;
    }

private function SetEmailContent(int $mode): bool 
    {
        $apiVer = $this->DetectEmailApiVersion();

        if ($apiVer === 'V20') {
            switch ($mode) {
                case 0: $payload = ["textType"=>1, "attachmentType"=>0]; break;
                case 1: $payload = ["textType"=>0, "attachmentType"=>1]; break;
                case 2: $payload = ["textType"=>1, "attachmentType"=>1]; break;
                case 3: $payload = ["textType"=>1, "attachmentType"=>2]; break;
                default: return false;
            }
            $res  = $this->apiCall([[ "cmd"=>"SetEmailV20", "param"=> [ "Email" => $payload ] ]]);
            return is_array($res) && isset($res[0]['code']) && $res[0]['code'] === 0;

        } else {
            switch ($mode) {
                case 0: $att = "0";            break;
                case 1: $att = "onlyPicture";  break;
                case 2: $att = "picture";      break;
                case 3: $att = "video";        break;
                default: return false;
            }
            $res  = $this->apiCall([[ "cmd"=>"SetEmail", "param"=> [ "Email" => [ "attachment" => $att ] ] ]]);
            return is_array($res) && isset($res[0]['code']) && $res[0]['code'] === 0;
        }
    }

    private function CheckAndCreatePTZUI(): void
    {
        if (!$this->ReadPropertyBoolean("ApiFunktionen") || !$this->apiEnsureToken()) {
            $this->RemovePTZUI();
            return;
        }

        $has = $this->DetectPTZ();
        $this->WriteAttributeBoolean("HasPTZ", $has);

        if ($has) {
            $this->CreateOrUpdatePTZHtml();
        } else {
            $this->RemovePTZUI();
        }
    }

    private function DetectPTZ(): bool
    {
        // 1) Fähigkeiten prüfen
        $res = $this->apiCall([[
            "cmd"   => "GetAbility",
            "param" => ["channel" => 0]
        ]], /*suppressError*/ true);

        if (is_array($res) && isset($res[0]['value'])) {
            // Mögliche Strukturvarianten der Firmware abdecken
            $v = $res[0]['value'];
            $ability =
                $v['Ability']      ??   // häufig
                $v['ability']      ??   // klein geschrieben
                $v['abilityChn']   ??   // kanal-spezifisch
                null;

            // Wenn abilityChn ein Array pro Kanal ist, Kanal 0 herausziehen
            if (is_array($ability) && isset($ability[0]) && is_array($ability[0])) {
                $ability = $ability[0];
            }

            if (is_array($ability)) {
                $flag =
                    $ability['ptz']        ??
                    $ability['PTZ']        ??
                    $ability['ptzType']    ??
                    $ability['ptzCtrl']    ??
                    $ability['ptzSupport'] ?? null;

                // Verschiedene Formen tolerieren (bool/int/array)
                if (is_bool($flag) && $flag) return true;
                if (is_numeric($flag) && (int)$flag > 0) return true;
                if (is_array($flag)) {
                    foreach ($flag as $vflag) {
                        if ((is_bool($vflag) && $vflag) || (is_numeric($vflag) && (int)$vflag > 0)) {
                            return true;
                        }
                    }
                }
            }
        }

        // 2) Harmloser Probe-Call: Stop (korrektes, flaches Param-Format!)
        $probe = $this->apiCall([[
            "cmd"   => "PtzCtrl",
            "param" => ["channel" => 0, "op" => "Stop"]
        ]], /*suppressError*/ true);
        if (is_array($probe) && (($probe[0]['code'] ?? -1) === 0)) {
            return true;
        }

        // 3) Alternativ: Presets abrufen (wenn unterstützt, ist PTZ sicher vorhanden)
        $probe2 = $this->apiCall([[
            "cmd"   => "GetPtzPreset",
            "param" => ["channel" => 0]
        ]], /*suppressError*/ true);
        if (is_array($probe2) && (($probe2[0]['code'] ?? -1) === 0)) {
            return true;
        }

        return false;
    }

    private function CreateOrUpdatePTZHtml(): void
    {
        if (!@$this->GetIDForIdent("PTZ_HTML")) {
            $this->RegisterVariableString("PTZ_HTML", "PTZ", "~HTMLBox", 8);
        }

        $hook = $this->ReadAttributeString("CurrentHook");

        $html = <<<HTML
    <div id="ptz-wrap" style="font-family:system-ui,Segoe UI,Roboto,Arial;max-width:240px">
    <style>
        #ptz-wrap button {
        padding:8px 10px; border:1px solid #bbb; border-radius:8px; cursor:pointer;
        background:#f7f7f7; font-size:16px; line-height:1;
        }
        #ptz-wrap button:active { transform:scale(0.98); }
        #ptz-wrap .grid { display:grid; grid-template-columns:repeat(3,1fr); gap:8px; place-items:center; }
        #ptz-msg { font-size:12px; color:#666; margin-top:6px; min-height:14px; }
        #ptz-wrap .ok { background:#e8ffe8; transition:background .3s; }
        #ptz-wrap .err{ background:#ffe8e8; transition:background .3s; }
    </style>

    <div class="grid">
        <button data-dir="up">▲</button>
        <button data-dir="stop">■</button>
        <button data-dir="home">⌂</button>
        <button data-dir="left">◀</button>
        <button data-dir="down">▼</button>
        <button data-dir="right">▶</button>
    </div>
    <div id="ptz-msg"></div>
    </div>
    <script>
    (function(){
    var base = '$hook';
    var msg  = document.getElementById('ptz-msg');
    var wrap = document.getElementById('ptz-wrap');

    function flash(cls){
        wrap.classList.add(cls);
        setTimeout(function(){ wrap.classList.remove(cls); }, 250);
    }

    function send(dir){
        // GET: /hook/... ?ptz=DIR
        var url = base + '?ptz=' + encodeURIComponent(dir);
        fetch(url, { method: 'GET', credentials: 'same-origin' })
        .then(function(r){ return r.text(); })
        .then(function(t){
            if ((t||'').trim().toUpperCase()==='OK') {
            msg.textContent = 'PTZ: ' + dir;
            flash('ok');
            } else {
            msg.textContent = 'Fehler: ' + (t||'');
            flash('err');
            }
        })
        .catch(function(e){
            msg.textContent = 'Netzwerkfehler';
            flash('err');
        });
    }

    Array.prototype.forEach.call(wrap.querySelectorAll('button[data-dir]'), function(btn){
        btn.addEventListener('click', function(){
        var dir = this.getAttribute('data-dir');
        send(dir);
        });
    });
    })();
    </script>
    HTML;

        $this->SetValue("PTZ_HTML", $html);
    }

    private function HandlePtzCommand(string $dir): void
    {
        $map = [
            'left'  => 'Left',
            'right' => 'Right',
            'up'    => 'Up',
            'down'  => 'Down',
            'stop'  => 'Stop',
            'home'  => 'Home' // falls unterstützt
        ];
        if (!isset($map[$dir])) {
            $this->SendDebug("PTZ", "Unbekannte Richtung: $dir", 0);
            return;
        }
        $op = $map[$dir];

        // Sofort-Stop/Home ohne „Impuls“
        if ($op === 'Stop' || $op === 'Home') {
            $this->PtzOp($op);
            return;
        }

        // kurzer Impuls (z. B. 250 ms) + Stop
        $this->PtzOp($op, 5); // speed 1..X (modellabhängig)
        IPS_Sleep(250);
        $this->PtzOp('Stop');
    }

    private function PtzOp(string $op, int $speed = 5): bool
    {
        $payload = [[
            "cmd"   => "PtzCtrl",
            "param" => [
                "channel" => 0,
                "op"      => $op,
                // speed nur mitsenden, wenn sinnvoll:
                // Stop/Home brauchen keinen speed
            ] + (($op === 'Stop' || $op === 'Home') ? [] : ["speed"=>$speed])
        ]];

        $res = $this->apiCall($payload, false);
        $ok  = is_array($res) && (($res[0]['code'] ?? -1) === 0);
        if (!$ok) $this->SendDebug("PTZ", "Fehler bei op=$op: ".json_encode($res), 0);
        return $ok;
    }
}
