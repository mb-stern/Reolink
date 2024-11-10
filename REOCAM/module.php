<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        // Diese Zeile nicht entfernen
        parent::Create();

        // Webhook registrieren
        $this->RegisterHook('/hook/reolink');
    }

    public function ApplyChanges()
    {
        // Diese Zeile nicht entfernen
        parent::ApplyChanges();

        // Webhook erneut registrieren, um sicherzustellen, dass er aktiv ist
        $this->RegisterHook('/hook/reolink');
    }

    private function RegisterHook($Hook)
    {
        // WebHook Control Modul suchen
        $ids = IPS_GetInstanceListByModuleID('{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}');
        if (count($ids) > 0) {
            $hookInstanceID = $ids[0];

            // Hooks-Liste abrufen und prüfen, ob der Hook bereits existiert
            $hooks = json_decode(IPS_GetProperty($hookInstanceID, 'Hooks'), true);
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $Hook) {
                    // Wenn der Hook existiert, nicht erneut hinzufügen
                    $this->SendDebug("RegisterHook", "Webhook bereits registriert: " . $Hook, 0);
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                // Wenn der Hook noch nicht existiert, hinzufügen
                $hooks[] = [
                    'Hook' => $Hook,
                    'TargetID' => $this->InstanceID
                ];
                IPS_SetProperty($hookInstanceID, 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($hookInstanceID);
                $this->SendDebug("RegisterHook", "Webhook erfolgreich registriert: " . $Hook, 0);
            }
        } else {
            $this->SendDebug("RegisterHook", "WebHook Control Modul nicht gefunden!", 0);
        }
    }
}

?>
