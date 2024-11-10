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

        // Webhook registrieren
        $this->RegisterHook('/hook/reolink');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');

        // Sicherstellen, dass das Bild- und Stream-Medienobjekt existieren oder erstellt werden
        $this->CreateOrUpdateMediaObject("Snapshot", "Kamera Snapshot", false);
        $this->CreateOrUpdateMediaObject("StreamURL", "Kamera Stream", true);
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

        // Snapshot-Bild bei jedem Webhook-Aufruf aktualisieren
        $this->UpdateSnapshot();
    }

    private function CreateOrUpdateMediaObject($ident, $name, $isStream)
    {
        // Überprüfen, ob das Medienobjekt existiert
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        if ($mediaID === false) {
            // Falls nicht vorhanden, Medienobjekt erstellen
            $mediaID = $isStream ? IPS_CreateMedia(3) : IPS_CreateMedia(1); // 3 für Stream, 1 für Bild
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $name);
            IPS_SetMediaCached($mediaID, true);
        }

        // Bei Stream-URL die Datei-URL setzen
        if ($isStream) {
            IPS_SetMediaFile($mediaID, $this->GetStreamURL(), false);
        }

        return $mediaID;
    }

    private function UpdateSnapshot()
    {
        // Erstellen oder Abrufen des Medienobjekts für das Bild
        $mediaID = $this->CreateOrUpdateMediaObject("Snapshot", "Kamera Snapshot", false);

        // URL zum Snapshot-Bild
        $snapshotUrl = $this->GetSnapshotURL();

        // Bildinhalt abrufen und aktualisieren
        $imageData = @file_get_contents($snapshotUrl);
        if ($imageData !== false) {
            IPS_SetMediaContent($mediaID, base64_encode($imageData));
            IPS_ApplyChanges($mediaID); // Änderungen anwenden, um das Bild zu aktualisieren
        } else {
            IPS_LogMessage("Reolink", "Snapshot konnte nicht abgerufen werden.");
        }
    }

    public function GetStreamURL()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");

        return "rtsp://$username:$password@$cameraIP:554//h264Preview_01_sub";
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
