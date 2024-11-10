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
        // WebHook Control Instanz suchen
        $ids = IPS_GetInstanceListByModuleID('{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}');
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $found = false;
            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $Hook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }
            if (!$found) {
                $hooks[] = ['Hook' => $Hook, 'TargetID' => $this->InstanceID];
            }
            IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        }
    }

    public function ProcessHookData()
    {
        $this->SendDebug('Webhook Triggered', 'Reolink Webhook wurde ausgelöst', 0);
        $data = file_get_contents('php://input');
        $this->SendDebug('Data Received', $data, 0);

        // Hier können Sie die erhaltenen Daten verarbeiten und in Variablen speichern
        IPS_LogMessage('Reolink', $data);
    }
}

?>
