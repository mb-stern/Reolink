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

        // Bool-Variablen mit dem Variablenprofil "~Motion" erstellen und Positionen festlegen
        $this->RegisterVariableBoolean("Person", "Person erkannt", "~Motion", 20);
        $this->RegisterVariableBoolean("Tier", "Tier erkannt", "~Motion", 25);
        $this->RegisterVariableBoolean("Fahrzeug", "Fahrzeug erkannt", "~Motion", 30);
        $this->RegisterVariableBoolean("Bewegung", "Bewegung allgemein", "~Motion", 35);

        // Kategorien und Medienobjekte für die Bildarchive und direkten Snapshots erstellen
        $this->CreateOrUpdateCategory("Person", "Bildarchiv Person", 22);
        $this->CreateOrUpdateCategory("Tier", "Bildarchiv Tier", 27);
        $this->CreateOrUpdateCategory("Fahrzeug", "Bildarchiv Fahrzeug", 32);
        $this->CreateOrUpdateCategory("Bewegung", "Bildarchiv Bewegung", 37);

        // Einzelsnapshots für die aktuellen Ansichten der Bool-Variablen
        $this->CreateOrUpdateImage("Snapshot_Person", "Aktueller Snapshot Person", 21);
        $this->CreateOrUpdateImage("Snapshot_Tier", "Aktueller Snapshot Tier", 26);
        $this->CreateOrUpdateImage("Snapshot_Fahrzeug", "Aktueller Snapshot Fahrzeug", 31);
        $this->CreateOrUpdateImage("Snapshot_Bewegung", "Aktueller Snapshot Bewegung", 36);

        // String-Variable `type` für Alarmtyp
        $this->RegisterVariableString("type", "Alarm Typ", "", 15);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');

        // Aktualisiere den Stream, falls der StreamType geändert wurde
        $this->CreateOrUpdateStream("StreamURL", "Kamera Stream");

        // Aktualisiere den allgemeinen Snapshot
        $this->UpdateSnapshot();
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

        // Aktualisiere den allgemeinen Snapshot bei jedem Webhook-Aufruf
        $this->UpdateSnapshot();
    }

    private function ProcessData($data)
    {
        if (isset($data['alarm']['type'])) {
            $type = $data['alarm']['type'];

            // Setze die `type`-Variable, um den aktuellen Typ zu speichern
            $this->SetValue("type", $type);

            switch ($type) {
                case "PEOPLE":
                    $this->ActivateBoolean("Person", "Bildarchiv Person", "Snapshot_Person");
                    break;
                case "ANIMAL":
                    $this->ActivateBoolean("Tier", "Bildarchiv Tier", "Snapshot_Tier");
                    break;
                case "VEHICLE":
                    $this->ActivateBoolean("Fahrzeug", "Bildarchiv Fahrzeug", "Snapshot_Fahrzeug");
                    break;
                case "MD":
                    $this->ActivateBoolean("Bewegung", "Bildarchiv Bewegung", "Snapshot_Bewegung");
                    break;
                default:
                    $this->SendDebug("Unknown Type", "Der Typ $type ist unbekannt.", 0);
                    break;
            }
        }

        // Aktualisiere zusätzliche Webhook-Variablen
        foreach ($data['alarm'] as $key => $value) {
            if ($key !== 'type') { // `type` wird bereits behandelt
                $this->updateVariable($key, $value);
            }
        }
    }

    private function ActivateBoolean($ident, $archiveName, $snapshotIdent)
    {
        $this->SetValue($ident, true);

        // Snapshot für das Bildarchiv und das Einzelbild erstellen und aktualisieren
        $this->CreateSnapshotInArchive($ident, $archiveName);
        $this->UpdateIndividualSnapshot($snapshotIdent);

        // Nach 5 Sekunden wieder auf false setzen
        IPS_Sleep(5000);
        $this->SetValue($ident, false);
    }

    private function CreateOrUpdateCategory($ident, $name, $position)
    {
        $categoryID = @IPS_GetObjectIDByIdent($ident . "_Archive", $this->InstanceID);
        if ($categoryID === false) {
            $categoryID = IPS_CreateCategory();
            IPS_SetParent($categoryID, $this->InstanceID);
            IPS_SetIdent($categoryID, $ident . "_Archive");
            IPS_SetName($categoryID, $name);
            IPS_SetPosition($categoryID, $position);
        }
        return $categoryID;
    }

    private function CreateSnapshotInArchive($booleanIdent, $archiveName)
    {
        $categoryID = $this->CreateOrUpdateCategory($booleanIdent, $archiveName, 0);

        // Bildinformationen abrufen
        $snapshotUrl = $this->GetSnapshotURL();
        $imageData = @file_get_contents($snapshotUrl);

        if ($imageData !== false) {
            $mediaID = IPS_CreateMedia(1); // 1 steht für Bild (PNG/JPG)
            IPS_SetParent($mediaID, $categoryID);
            IPS_SetPosition($mediaID, -time()); // Negative Zeit, damit neue Bilder oben erscheinen
            IPS_SetName($mediaID, "Snapshot " . date("Y-m-d H:i:s"));
            IPS_SetMediaCached($mediaID, false);

            $imageFilePath = IPS_GetKernelDir() . "media" . DIRECTORY_SEPARATOR . "snapshot_" . time() . ".jpg";
            file_put_contents($imageFilePath, $imageData);
            IPS_SetMediaFile($mediaID, $imageFilePath, false);

            // Anzahl der Snapshots im Archiv begrenzen
            $this->LimitSnapshotCount($categoryID, 20);
        } else {
            IPS_LogMessage("Reolink", "Snapshot konnte nicht abgerufen werden für $booleanIdent.");
        }
    }

    private function UpdateIndividualSnapshot($ident)
    {
        $mediaID = $this->CreateOrUpdateImage($ident, "Aktueller Snapshot $ident", 5);

        // Bildinhalt abrufen
        $snapshotUrl = $this->GetSnapshotURL();
        $imageData = @file_get_contents($snapshotUrl);

        if ($imageData !== false) {
            $tempImagePath = IPS_GetKernelDir() . "media" . DIRECTORY_SEPARATOR . "$ident.jpg";
            file_put_contents($tempImagePath, $imageData);
            IPS_SetMediaFile($mediaID, $tempImagePath, false);
            IPS_SendMediaEvent($mediaID); // Medienobjekt aktualisieren
        } else {
            IPS_LogMessage("Reolink", "Aktueller Snapshot $ident konnte nicht abgerufen werden.");
        }
    }

    private function LimitSnapshotCount($categoryID, $limit)
    {
        $childrenIDs = IPS_GetChildrenIDs($categoryID);
        $snapshotCount = count($childrenIDs);

        if ($snapshotCount > $limit) {
            // Überschüssige Snapshots löschen
            for ($i = 0; $i < $snapshotCount - $limit; $i++) {
                IPS_DeleteMedia($childrenIDs[$i], true);
            }
        }
    }

    private function UpdateSnapshot()
    {
        // Snapshot-ID abrufen oder erstellen
        $mediaID = $this->CreateOrUpdateImage("Snapshot", "Aktueller Snapshot", 5);

        // Bildinhalt abrufen
        $snapshotUrl = $this->GetSnapshotURL();
        $imageData = @file_get_contents($snapshotUrl);

        if ($imageData !== false) {
            $tempImagePath = IPS_GetKernelDir() . "media" . DIRECTORY_SEPARATOR . "current_snapshot.jpg";
            file_put_contents($tempImagePath, $imageData);
            IPS_SetMediaFile($mediaID, $tempImagePath, false);
            IPS_SendMediaEvent($mediaID); // Medienobjekt aktualisieren
        } else {
            IPS_LogMessage("Reolink", "Aktueller Snapshot konnte nicht abgerufen werden.");
        }
    }

    private function CreateOrUpdateImage($ident, $name, $position)
    {
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(1); // 1 steht für Bild (PNG/JPG)
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetPosition($mediaID, $position);
            IPS_SetName($mediaID, $name);
            IPS_SetMediaCached($mediaID, false);
        }

        return $mediaID;
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

        // Stream-URL aktualisieren
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
