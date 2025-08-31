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
        $this->RegisterPropertyBoolean("EnablePolling", false);
        $this->RegisterPropertyBoolean("EnableApiWhiteLed", true);
        $this->RegisterPropertyBoolean("EnableApiEmail", true);
        $this->RegisterPropertyBoolean("EnableApiPTZ", false);
        
        $this->RegisterPropertyInteger("PollingInterval", 2);
        $this->RegisterPropertyInteger("MaxArchiveImages", 20);
        
        $this->RegisterAttributeBoolean("ApiInitialized", false);
        $this->RegisterAttributeBoolean("TokenRefreshing", false);
        
        $this->RegisterAttributeInteger("ApiTokenExpiresAt", 0);
        
        $this->RegisterAttributeString("CurrentHook", "");
        $this->RegisterAttributeString("ApiToken", "");
        $this->RegisterAttributeString("EmailApiVersion", "");
        $this->RegisterAttributeString("PtzStyle", "");


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

        // --- Hook sicherstellen ---
        $hookPath = $this->ReadAttributeString("CurrentHook");
        if ($hookPath === "") {
            $hookPath = $this->RegisterHook();
            $this->SendDebug('ApplyChanges', "Die Initialisierung des Hook-Pfades '$hookPath' gestartet.", 0);
        }

        // --- Stream aktualisieren ---
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");

        // --- Bewegungs-/Snapshot-/Archiv-/Test-/Besucher-Elemente ---
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

        // --- Polling ---
        if ($this->ReadPropertyBoolean("EnablePolling")) {
            $interval = $this->ReadPropertyInteger("PollingInterval");
            $this->SetTimerInterval("PollingTimer", $interval * 1000);
        } else {
            $this->SetTimerInterval("PollingTimer", 0);
        }

        // --- Einzelschalter für API-Funktionen ---
        $enableWhiteLed = $this->ReadPropertyBoolean("EnableApiWhiteLed");
        $enableEmail    = $this->ReadPropertyBoolean("EnableApiEmail");
        $enablePTZ      = $this->ReadPropertyBoolean("EnableApiPTZ");

        $anyFeatureOn = ($enableWhiteLed || $enableEmail || $enablePTZ);

        if ($anyFeatureOn) {
            // Timer für periodische API-Abfragen / Token
            $this->SetTimerInterval("ApiRequestTimer", 10 * 1000);
            // optional schöner: hier 0 lassen, GetToken setzt später ~50min
            $this->SetTimerInterval("TokenRenewalTimer", 0);

            // Initialzustand zurücksetzen, Variablen anlegen, Token holen, erste Abfragen
            $this->WriteAttributeBoolean("ApiInitialized", false);
            $this->CreateApiVariables();   // legt nur an, was per Schalter aktiv ist
            $this->GetToken();
            $this->ExecuteApiRequests();   // aktualisiert nur aktive Feature-Gruppen
        } else {
            // Alles aus: Timer stoppen, Variablen entfernen (inkl. PTZ_HTML)
            $this->SetTimerInterval("ApiRequestTimer",   0);
            $this->SetTimerInterval("TokenRenewalTimer", 0);
            $this->RemoveApiVariables();
        }
    }

    private function RemoveApiVariables(): void
    {
        $idents = [
            // White LED
            "WhiteLed", "Mode", "Bright",
            // Email
            "EmailNotify", "EmailInterval", "EmailContent",
            // PTZ
            "PTZ_HTML"
        ];

        foreach ($idents as $ident) {
            $id = @$this->GetIDForIdent($ident);
            if ($id !== false) {
                $this->UnregisterVariable($ident);
            }
        }
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

        $hookPath = $this->ReadAttributeString("CurrentHook");
        $webhookElement = [
            "type"    => "Label",
            "name"    => "WebhookPath",
            "caption" => "Webhook: " . $hookPath
        ];

        array_splice($form['elements'], 0, 0, [$webhookElement]);

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
        while (ob_get_level() > 0) { @ob_end_clean(); }
        
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
        $enableWhiteLed = $this->ReadPropertyBoolean("EnableApiWhiteLed");
        $enableEmail    = $this->ReadPropertyBoolean("EnableApiEmail");
        $enablePTZ      = $this->ReadPropertyBoolean("EnableApiPTZ");

        /* --- WHITE LED --- */
        if ($enableWhiteLed) {
            if (!IPS_VariableProfileExists("REOCAM.WLED")) {
                IPS_CreateVariableProfile("REOCAM.WLED", 1);
                IPS_SetVariableProfileValues("REOCAM.WLED", 0, 2, 1);
                IPS_SetVariableProfileDigits("REOCAM.WLED", 0);
                IPS_SetVariableProfileAssociation("REOCAM.WLED", 0, "Aus", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.WLED", 1, "Automatisch", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.WLED", 2, "Zeitabhängig", "", -1);
            }
            if (!@$this->GetIDForIdent("WhiteLed")) {
                $this->RegisterVariableBoolean("WhiteLed", "LED Status", "~Switch", 0);
                $this->EnableAction("WhiteLed");
            }
            if (!@$this->GetIDForIdent("Mode")) {
                $this->RegisterVariableInteger("Mode", "LED Modus", "REOCAM.WLED", 1);
                $this->EnableAction("Mode");
            }
            if (!@$this->GetIDForIdent("Bright")) {
                $this->RegisterVariableInteger("Bright", "LED Helligkeit", "~Intensity.100", 2);
                $this->EnableAction("Bright");
            }
        } else {
            foreach (["WhiteLed","Mode","Bright"] as $ident) {
                $id = @$this->GetIDForIdent($ident);
                if ($id !== false) $this->UnregisterVariable($ident);
            }
        }

        /* --- EMAIL --- */
        if ($enableEmail) {
            if (!IPS_VariableProfileExists("REOCAM.EmailInterval")) {
                IPS_CreateVariableProfile("REOCAM.EmailInterval", 1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 30,   "30 Sek.",    "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 60,   "1 Minute",   "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 300,  "5 Minuten",  "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 600,  "10 Minuten", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailInterval", 1800, "30 Minuten", "", -1);
            }
            if (!IPS_VariableProfileExists("REOCAM.EmailContent")) {
                IPS_CreateVariableProfile("REOCAM.EmailContent", 1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 0, "Text", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 1, "Bild (ohne Text)", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 2, "Text + Bild", "", -1);
                IPS_SetVariableProfileAssociation("REOCAM.EmailContent", 3, "Text + Video", "", -1);
            }
            if (!@$this->GetIDForIdent("EmailNotify")) {
                $this->RegisterVariableBoolean("EmailNotify", "E-Mail Versand", "~Switch", 3);
                $this->EnableAction("EmailNotify");
            }
            if (!@$this->GetIDForIdent("EmailInterval")) {
                $this->RegisterVariableInteger("EmailInterval", "E-Mail Intervall", "REOCAM.EmailInterval", 4);
                $this->EnableAction("EmailInterval");
            }
            if (!@$this->GetIDForIdent("EmailContent")) {
                $this->RegisterVariableInteger("EmailContent", "E-Mail Inhalt", "REOCAM.EmailContent", 5);
                $this->EnableAction("EmailContent");
            }
        } else {
            foreach (["EmailNotify","EmailInterval","EmailContent"] as $ident) {
                $id = @$this->GetIDForIdent($ident);
                if ($id !== false) $this->UnregisterVariable($ident);
            }
        }

        /* --- PTZ (HTML-Box) --- */
        if ($enablePTZ) {
            // anlegen (falls fehlt)
            if (!@$this->GetIDForIdent("PTZ_HTML")) {
                $this->RegisterVariableString("PTZ_HTML", "PTZ", "~HTMLBox", 8);
            }
            // UI jetzt erzeugen/aktualisieren (setzt nur bei Änderung)
            $this->CreateOrUpdatePTZHtml();
        } else {
            // komplett entfernen (nicht nur verstecken)
            $id = @$this->GetIDForIdent("PTZ_HTML");
            if ($id !== false) {
                $this->UnregisterVariable("PTZ_HTML");
            }
        }
    }

    public function ExecuteApiRequests()
    {
        if (!$this->apiEnsureToken()) return;

        if ($this->ReadPropertyBoolean("EnableApiWhiteLed")) {
            $this->UpdateWhiteLedStatus();
        }
        if ($this->ReadPropertyBoolean("EnableApiEmail")) {
            $this->UpdateEmailVars();
        }
        if ($this->ReadPropertyBoolean("EnableApiPTZ")) {
            $this->CreateOrUpdatePTZHtml();
        }
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

    /** Erzeugt/aktualisiert die PTZ-HTML-Box inkl. Preset-Verwaltung */
    private function CreateOrUpdatePTZHtml(): void
    {
        // Variable anlegen (falls nicht vorhanden) und sichtbar schalten
        if (!@$this->GetIDForIdent("PTZ_HTML")) {
            $this->RegisterVariableString("PTZ_HTML", "PTZ", "~HTMLBox", 8);
        }
        $id = $this->GetIDForIdent("PTZ_HTML");
        if ($id !== false) { @IPS_SetHidden($id, false); }

        // Hook sicherstellen
        $hook = $this->ReadAttributeString("CurrentHook");
        if ($hook === "") {
            $hook = $this->RegisterHook();
        }

        // Presets holen und Buttons bauen (eine Zeile pro Preset mit Fahr-, Umbenenn- und Lösch-Button)
        $presets = $this->getPresetList();
        $presetRows = '';
        if (!empty($presets)) {
            foreach ($presets as $p) {
                $pid   = (int)$p['id'];
                $title = htmlspecialchars($p['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $presetRows .= '
                <div class="preset-row">
                    <button class="preset" data-preset="'.$pid.'" title="'.$title.'">['.$pid.'] '.$title.'</button>
                    <button class="preset-rename" data-id="'.$pid.'" title="Umbenennen">✎</button>
                    <button class="preset-delete" data-id="'.$pid.'" title="Löschen">🗑</button>
                </div>';
            }
        } else {
            $presetRows = '<div class="no-presets">Keine Presets gefunden.</div>';
        }

        // Verwaltungsbereich zum Speichern/Überschreiben (ID + optional Name)
        $manage = <<<H
    <div class="section-title">Preset verwalten</div>
    <div class="manage">
    <div class="row">
        <input id="ptz-save-id"   type="number" min="0" placeholder="ID" style="width:80px; margin-right:6px;">
        <input id="ptz-save-name" type="text"   placeholder="Name (optional)" style="flex:1; margin-right:6px;">
        <button id="ptz-btn-save"   title="Aktuelle Position als Preset speichern/überschreiben">Speichern</button>
    </div>
    </div>
    H;

        // kompakte Styles
        $btn = 42; // Kantenlänge für die Richtungsbuttons (px)
        $gap = 6;  // Abstand (px)

        $html = <<<HTML
    <div id="ptz-wrap" style="font-family:system-ui,Segoe UI,Roboto,Arial; overflow:hidden;">
    <style>
    #ptz-wrap{
        --btn: {$btn}px;
        --gap: {$gap}px;
        --fs: 16px;
        --radius: 10px;
        max-width: 560px;
        margin: 0 auto;
        user-select: none;
    }
    #ptz-wrap .grid{
        display:grid;
        grid-template-columns: repeat(3, var(--btn));
        grid-template-rows:    repeat(3, var(--btn));
        gap: var(--gap);
        justify-content:center;
        align-items:center;
        margin-bottom: 10px;
    }
    #ptz-wrap button{
        height: var(--btn);
        border: 1px solid #cfcfcf;
        border-radius: var(--radius);
        background: #f8f8f8;
        font-size: var(--fs);
        line-height: 1;
        cursor: pointer;
        box-shadow: 0 1px 2px rgba(0,0,0,.06);
        box-sizing: border-box;
        padding: 6px 10px;
    }
    #ptz-wrap .dir { width: var(--btn); padding: 0; }
    #ptz-wrap button:hover { filter: brightness(.98); }
    #ptz-wrap button:active{ transform: translateY(1px); }

    #ptz-wrap .up    { grid-column:2; grid-row:1; }
    #ptz-wrap .left  { grid-column:1; grid-row:2; }
    #ptz-wrap .right { grid-column:3; grid-row:2; }
    #ptz-wrap .down  { grid-column:2; grid-row:3; }

    #ptz-wrap .section-title{
        font-weight: 600;
        margin: 10px 0 6px 0;
    }

    #ptz-wrap .presets{
        display: block; /* eine Zeile pro Preset */
    }
    #ptz-wrap .preset-row{
        display:flex;
        gap: var(--gap);
        align-items:center;
        margin-bottom: var(--gap);
    }
    #ptz-wrap .preset{
        flex: 1;
        height: auto;
        min-height: 36px;
        padding: 8px 12px;
        text-align: left; /* Namen linksbündig, falls lang */
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    #ptz-wrap .preset-rename, #ptz-wrap .preset-delete{
        min-width: 36px;
        height: 36px;
        padding: 6px 8px;
    }

    #ptz-wrap .manage .row{
        display:flex;
        gap: var(--gap);
        align-items:center;
    }

    #ptz-wrap .status{ display:none; }
    #ptz-wrap .no-presets{ opacity:.7; padding:4px 0; }
    </style>

    <div class="grid">
    <button data-dir="up"    class="dir up"    title="Hoch"   aria-label="Hoch">▲</button>
    <button data-dir="left"  class="dir left"  title="Links"  aria-label="Links">◀</button>
    <button data-dir="right" class="dir right" title="Rechts" aria-label="Rechts">▶</button>
    <button data-dir="down"  class="dir down"  title="Runter" aria-label="Runter">▼</button>
    </div>

    <div class="section-title">Presets</div>
    <div class="presets">
    {$presetRows}
    </div>

    {$manage}

    <div class="status" id="ptz-msg"></div>
    </div>

    <script>
    (function(){
    var base = "{$hook}";
    var msg  = document.getElementById("ptz-msg");
    var wrap = document.getElementById("ptz-wrap");

    function callQS(qs){
        fetch(base + "?" + qs, { method:"GET", credentials:"same-origin", cache:"no-store" })
        .then(function(r){ return r.text(); })
        .then(function(t){
            if ((t||"").trim().toUpperCase() !== "OK") {
            if (msg) msg.textContent = "Fehler: " + (t||"");
            }
        })
        .catch(function(){ if (msg) msg.textContent = "Netzwerkfehler"; });
    }

  function callParam(param){ callQS("ptz=" + encodeURIComponent(param)); }

  wrap.addEventListener("click", function(ev){
    var btn = ev.target.closest("button");
    if (!btn) return;

    // Richtungen & Preset anfahren
    if (btn.hasAttribute("data-dir"))    { callParam(btn.getAttribute("data-dir")); return; }
    if (btn.hasAttribute("data-preset")) { callParam("preset:" + btn.getAttribute("data-preset")); return; }

    // SAVE (oben im Verwaltungsbereich)
    if (btn.id === "ptz-btn-save") {
      var idEl   = document.getElementById("ptz-save-id");
      var nameEl = document.getElementById("ptz-save-name");
      var id     = idEl && idEl.value !== "" ? parseInt(idEl.value,10) : NaN;
      var name   = nameEl ? nameEl.value : "";
      if (isNaN(id) || id < 0) { if (msg) msg.textContent = "Bitte gültige ID angeben."; return; }
      callQS("ptz=save&id=" + encodeURIComponent(id) + (name ? "&name=" + encodeURIComponent(name) : ""));
      return;
    }

    // RENAME je Zeile
    if (btn.classList.contains("preset-rename")) {
      var id  = parseInt(btn.getAttribute("data-id"),10);
      var neu = prompt("Neuer Name für Preset " + id + ":");
      if (neu && neu.trim() !== "") {
        callQS("ptz=rename&id=" + encodeURIComponent(id) + "&name=" + encodeURIComponent(neu.trim()));
      }
      return;
    }

    // DELETE je Zeile
    if (btn.classList.contains("preset-delete")) {
      var id = parseInt(btn.getAttribute("data-id"),10);
      if (confirm("Preset " + id + " wirklich löschen?")) {
        callQS("ptz=delete&id=" + encodeURIComponent(id));
      }
      return;
    }
  });
})();
</script>
HTML;

    // Nur setzen, wenn sich der Wert ändert
    $this->setHtmlIfChanged("PTZ_HTML", $html);
}


    /** Setzt eine String-Variable nur, wenn der neue Inhalt sich unterscheidet. */
    private function setHtmlIfChanged(string $ident, string $html): void
    {
        $id = @$this->GetIDForIdent($ident);
        $old = ($id !== false) ? GetValue($id) : null;
        if (!is_string($old) || $old !== $html) {
            $this->SetValue($ident, $html);
        } else {
            $this->SendDebug($ident, 'Unverändert – kein Update', 0);
        }
    }

    private function HandlePtzCommand(string $cmd): void
    {
        // --- neue Aktions-API: save/rename/delete mit id (+ name) als Query-Param ---
        if ($cmd === 'save' || $cmd === 'rename' || $cmd === 'delete') {
            $id = null;
            if (isset($_POST['id']))       $id = (int)$_POST['id'];
            elseif (isset($_GET['id']))    $id = (int)$_GET['id'];

            $name = null;
            if (isset($_POST['name']))     $name = (string)$_POST['name'];
            elseif (isset($_GET['name']))  $name = (string)$_GET['name'];

            if ($id === null || $id < 0) {
                $this->SendDebug('PTZ', "Ungültige ID für Aktion '$cmd'", 0);
                return;
            }

            $ok = false;
            switch ($cmd) {
                case 'save':
                    // Name optional – wenn gesetzt, als Bezeichnung übernehmen
                    $ok = $this->PTZ_SavePreset($id, ($name !== null && $name !== '') ? $name : null);
                    break;

                case 'rename':
                    if ($name === null || $name === '') {
                        $this->SendDebug('PTZ', "Rename ohne Namen (id=$id)", 0);
                        return;
                    }
                    $ok = $this->PTZ_RenamePreset($id, $name);
                    break;

                case 'delete':
                    $ok = $this->PTZ_DeletePreset($id);
                    break;
            }

            if (!$ok) {
                $this->SendDebug('PTZ', "Aktion '$cmd' fehlgeschlagen (id=$id, name=".($name ?? '').")", 0);
            }
            return;
        }

        // --- bestehende Logik: Preset anfahren ---
        if (strpos($cmd, 'preset:') === 0) {
            $id = (int)substr($cmd, 7);
            if ($id >= 0) {
                $this->ptzGotoPreset($id);
            } else {
                $this->SendDebug("PTZ", "Ungueltige Preset-ID: $cmd", 0);
            }
            return;
        }

        // --- bestehende Logik: Pfeilsteuerung ---
        $map = [
            'left'  => 'Left',
            'right' => 'Right',
            'up'    => 'Up',
            'down'  => 'Down',
            'stop'  => 'Stop'
        ];
        if (!isset($map[$cmd])) {
            $this->SendDebug("PTZ", "Unbekannter Befehl: $cmd", 0);
            return;
        }
        $this->ptzCtrl($map[$cmd]);
    }

    private function getPtzStyle(): string {
    $s = $this->ReadAttributeString("PtzStyle");
    return ($s === "flat" || $s === "nested") ? $s : "";
    }

    private function setPtzStyle(string $s): void {
        if ($s === "flat" || $s === "nested") {
            $this->WriteAttributeString("PtzStyle", $s);
            $this->SendDebug("PTZ", "PtzStyle gesetzt: ".$s, 0);
        }
    }

    private function ptzOk(?array $res): bool {
    return is_array($res) && (($res[0]['code'] ?? -1) === 0);
    }

    /** Ein Call, der automatisch flat/nested probiert und den Stil cached. */
    private function postCmdDual(string $cmd, array $body, ?string $nestedKey=null, bool $suppress=false): ?array
    {
        $nestedKey = $nestedKey ?: $cmd;

        $known = $this->getPtzStyle();                 // "flat", "nested" oder ""
        $order = $known ? [$known, ($known === 'flat' ? 'nested' : 'flat')] : ['flat','nested'];

        foreach ($order as $mode) {
            $payload = [[
                'cmd'   => $cmd,
                'param' => ($mode === 'flat') ? $body : [$nestedKey => $body]
            ]];

            $resp = $this->apiCall($payload, /*suppress*/ true);
            if (is_array($resp) && (($resp[0]['code'] ?? -1) === 0)) {
                if ($known !== $mode) $this->setPtzStyle($mode);
                return $resp;
            }
        }

        if (!$suppress) {
            $this->SendDebug("postCmdDual/$cmd", "FAIL body=".json_encode($body), 0);
            $this->LogMessage("Reolink: postCmdDual FAIL for {$cmd}", KL_ERROR);
        }
        return null;
    }

    /** Pfeil/Einzel-PTZ (impulsartig). */
    private function ptzCtrl(string $op, array $extra = [], int $pulseMs = 250): bool
    {
        $param = ['channel'=>0, 'op'=>$op] + $extra;

        // Speed nur bei Beweg-OPs geben
        if (in_array($op, ['Left','Right','Up','Down'], true)) {
            if (!isset($param['speed'])) $param['speed'] = 5;
        }

        $ok = is_array($this->postCmdDual('PtzCtrl', $param, 'PtzCtrl', /*suppress*/ false));
        if (!$ok) return false;

        // Impuls (nur bei Pfeilen), danach Stop
        if (in_array($op, ['Left','Right','Up','Down'], true)) {
            IPS_Sleep($pulseMs);
            $this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'Stop'], 'PtzCtrl', /*suppress*/ true);
        }
        return true;
    }

    /** Preset anfahren: versucht ToPos, dann ToPreset. */
    private function ptzGotoPreset(int $id): bool
    {
        // Erst ToPos
        $ok = is_array($this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'ToPos', 'id'=>$id], 'PtzCtrl', /*suppress*/ true));
        if ($ok) return true;

        // Fallback ToPreset
        $ok = is_array($this->postCmdDual('PtzCtrl', ['channel'=>0, 'op'=>'ToPreset', 'id'=>$id], 'PtzCtrl', /*suppress*/ true));
        if ($ok) return true;

        $this->SendDebug('PTZ/PRESET', "Anfahren fehlgeschlagen für ID=$id", 0);
        return false;
    }

    /** Rekursiv nach Preset-Arrays suchen (Einträge mit 'id' + optional 'name'),
     *  generische Platzhalter (pos1/pos 01/preset2/position3 ...) ausfiltern,
     *  sofern keine Set-Flags oder Positionswerte vorhanden sind.
     */
    private function collectPresetsRecursive($node, array &$out, array &$seen): void
    {
        if (!is_array($node) || empty($node)) return;

        // Fall: Liste von Preset-Objekten
        $first = reset($node);
        if (is_array($first) && (isset($first['id']) || isset($first['Id']))) {
            foreach ($node as $p) {
                if (!is_array($p)) continue;

                $rawId = $p['id'] ?? $p['Id'] ?? null;
                if (!is_numeric($rawId)) continue;
                $id = (int)$rawId;

                if (isset($seen[$id])) continue; // Dedupe

                // Name lesen
                $name = $p['name'] ?? $p['Name'] ?? $p['sName'] ?? $p['label'] ?? $p['presetName'] ?? '';
                $name = (string)$name;
                $trim = trim($name);

                // --- integrierte Platzhalter-Erkennung (pos/preset/position + Zahl) ---
                // Beispiele: "pos1", "pos 01", "preset2", "position3" (Groß/klein egal)
                $isGeneric = ($trim !== '') && (preg_match('/^(pos|preset|position)\s*0*\d+$/i', $trim) === 1);

                // Heuristik "belegt": typische Flags und/oder Koordinaten
                $flag  = $p['exist'] ?? $p['bExist'] ?? $p['bexistPos'] ?? $p['enable'] ?? $p['enabled'] ?? $p['set'] ?? $p['bSet'] ?? null;
                $isSet = ($flag === 1 || $flag === '1' || $flag === true);

                $posArr = $p['pos'] ?? $p['position'] ?? $p['ptzpos'] ?? $p['ptz'] ?? null;
                $hasPos = false;
                if (is_array($posArr)) {
                    foreach ($posArr as $v) {
                        if (is_numeric($v) && (float)$v != 0.0) { $hasPos = true; break; }
                    }
                }

                // Filter: Nur dann überspringen, wenn (Name leer ODER generisch) UND keine Flags/Koordinaten
                if (($trim === '' || $isGeneric) && !$isSet && !$hasPos) {
                    continue;
                }

                if ($trim === '') $name = "Preset ".$id;

                $out[] = ['id'=>$id, 'name'=>$name];
                $seen[$id] = true;
            }
            // nicht return; darunter weiter tiefer suchen
        }

        // Tiefer rekursiv durchsuchen
        foreach ($node as $v) {
            if (is_array($v)) {
                $this->collectPresetsRecursive($v, $out, $seen);
            }
        }
    }

    /** Liest Presets robust aus GetPtzPreset (versch. FW-Varianten). */
    private function getPresetList(): array
    {
        $res = $this->postCmdDual('GetPtzPreset', ['channel'=>0], 'GetPtzPreset', /*suppress*/ true);
        $list = [];
        $seen = [];

        if (is_array($res)) {
            // 1) Standard-Pfade
            $v  = $res[0]['value'] ?? [];
            $ps = $v['PtzPreset']['preset'] ?? $v['PtzPreset']['table'] ?? $v['preset'] ?? $v['table'] ?? null;

            if (is_array($ps)) {
                $this->collectPresetsRecursive($ps, $list, $seen);
            } else {
                // 2) Fallback: komplett rekursiv durchsuchen (beliebige Container-Namen)
                $this->collectPresetsRecursive($v, $list, $seen);
            }

            // 3) Wenn weiterhin leer: komplettes Response durchsuchen
            if (empty($list)) {
                $this->collectPresetsRecursive($res, $list, $seen);
            }

            // 4) Debug-Hinweis, falls nix gefunden wurde
            if (empty($list)) {
                $keys = is_array($v) ? implode(',', array_keys($v)) : '';
                $this->SendDebug('GetPtzPreset', 'Keine Presets erkannt. value-Keys='.$keys.' RAW='.json_encode($res), 0);
            }
        } else {
            $this->SendDebug('GetPtzPreset', 'Kein gueltiges Array-Response', 0);
        }

        // nach ID sortieren (optional)
        usort($list, fn($a,$b) => $a['id'] <=> $b['id']);
        return $list;
    }

    /** Aktuelle Position als Preset speichern (id = 0..n) */
    private function ptzSetPreset(int $id): bool {
        // Standard: über PtzCtrl
        if (is_array($this->postCmdDual('PtzCtrl', ['channel'=>0,'op'=>'SetPreset','id'=>$id], 'PtzCtrl', /*suppress*/true))) return true;
        // Sehr alte FW: SetPos
        if (is_array($this->postCmdDual('PtzCtrl', ['channel'=>0,'op'=>'SetPos','id'=>$id], 'PtzCtrl', /*suppress*/true))) return true;
        $this->SendDebug('PTZ/SetPreset',"Fehlgeschlagen für id=$id",0);
        return false;
    }

    /** Preset löschen */
    private function ptzClearPreset(int $id): bool {
        if (is_array($this->postCmdDual('PtzCtrl', ['channel'=>0,'op'=>'ClearPreset','id'=>$id], 'PtzCtrl', /*suppress*/true))) return true;
        $this->SendDebug('PTZ/ClearPreset',"Fehlgeschlagen für id=$id",0);
        return false;
    }

    /** Preset umbenennen (Firmware-Varianten nacheinander probieren) */
    private function ptzRenamePreset(int $id, string $name): bool {
        $body = ['channel'=>0,'id'=>$id,'name'=>$name];

        // a) Symmetrisch zu GetPtzPreset
        if (is_array($this->postCmdDual('SetPtzPreset', $body, 'PtzPreset', /*suppress*/true))) return true;

        // b) Über "PtzPreset" mit cmd=SetName
        if (is_array($this->postCmdDual('PtzPreset', $body + ['cmd'=>'SetName'], 'PtzPreset', /*suppress*/true))) return true;

        // c) Manche FW: PtzCtrl-Variante
        if (is_array($this->postCmdDual('PtzCtrl', ['channel'=>0,'op'=>'SetPresetName','id'=>$id,'name'=>$name], 'PtzCtrl', /*suppress*/true))) return true;

        $this->SendDebug('PTZ/Rename',"Fehlgeschlagen für id=$id, name=$name",0);
        return false;
    }

    /** Bequeme öffentliche Methoden für Skripte */
    public function PTZ_SavePreset(int $id, ?string $name=null): bool {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzSetPreset($id);
        if ($ok && $name) { $this->ptzRenamePreset($id, $name); }
        $this->CreateOrUpdatePTZHtml(); // UI refresh
        return $ok;
    }
    public function PTZ_RenamePreset(int $id, string $name): bool {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzRenamePreset($id, $name);
        if ($ok) $this->CreateOrUpdatePTZHtml();
        return $ok;
    }
    public function PTZ_DeletePreset(int $id): bool {
        if (!$this->apiEnsureToken()) return false;
        $ok = $this->ptzClearPreset($id);
        if ($ok) $this->CreateOrUpdatePTZHtml();
        return $ok;
    }
}