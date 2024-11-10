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

        // Medienobjekte für Stream und Snapshot erstellen
        $this->RegisterMediaObjects();

        // Timer für das regelmäßige Aktualisieren des Snapshot-Bildes registrieren (alle 60 Sekunden)
        $this->RegisterTimer("UpdateSnapshot", 60000, 'Reolink_UpdateSnapshot($_IPS["TARGET"]);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');

        // URLs für Medienobjekte aktualisieren
        $this->UpdateMediaObjects();
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

    private function RegisterMediaObjects()
    {
        // Medienobjekte für Stream und Snapshot erstellen
        $this->RegisterStreamMediaObject("StreamURL", "Kamera Stream", $this->GetStreamURL());
        $this->RegisterImageMediaObject("Snapshot", "Kamera Snapshot");
    }

    private function RegisterStreamMediaObject($ident, $name, $url)
    {
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(3); // 3 steht für Stream-URL
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $name);
            IPS_SetMediaFile($mediaID, $url, false);
            IPS_SetMediaCached($mediaID, true);
        }
    }

    private function RegisterImageMediaObject($ident, $name)
    {
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($mediaID === false) {
            $mediaID = IPS_CreateMedia(1); // 1 steht für Bild (PNG/JPG)
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, $ident);
            IPS_SetName($mediaID, $name);
            IPS_SetMediaCached($mediaID, true);
        }
    }

    private function UpdateMediaObjects()
    {
        $this->UpdateMediaObject("StreamURL", $this->GetStreamURL(), true);
        $this->UpdateSnapshot(); // Aktualisiert das Snapshot-Bild initial
    }

    private function UpdateSnapshot()
    {
        $snapshotUrl = $this->GetSnapshotURL();
        $mediaID = @IPS_GetObjectIDByIdent("Snapshot", $this->InstanceID);

        if ($mediaID !== false) {
            $imageData = @file_get_contents($snapshotUrl);
            if ($imageData !== false) {
                IPS_SetMediaContent($mediaID, base64_encode($imageData));
                IPS_ApplyChanges($mediaID); // Änderungen anwenden, um das Bild zu aktualisieren
            } else {
                IPS_LogMessage("Reolink", "Snapshot konnte nicht abgerufen werden.");
            }
        }
    }

    private function UpdateMediaObject($ident, $url, $isStream)
    {
        $mediaID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($mediaID !== false) {
            IPS_SetMediaFile($mediaID, $url, !$isStream);
        }
    }

    public function GetStreamURL()
    {
        $cameraIP = $this->ReadPropertyString("CameraIP");
        $username = $this->ReadPropertyString("Username");
        $password = $this->ReadPropertyString("Password");

        return "rtsp://$username:$password@$cameraIP:554//h264Preview_01_main";
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
