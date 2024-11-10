<?php

class Reolink extends IPSModule
{
    private $hook = 'reolink';

    public function Create()
    {
        // Diese Zeile darf nicht entfernt werden.
        parent::Create();

        // Kernel READY Nachricht abonnieren
        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        parent::MessageSink($TimeStamp, $SenderID, $Message, $Data);

        // Prüfen, ob Kernel bereit ist
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->RegisterHook('/hook/' . $this->hook);
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Webhook nur im READY-Status registrieren
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook('/hook/' . $this->hook);
        }
    }

    private function RegisterHook($WebHook)
    {
        $ids = IPS_GetInstanceListByModuleID('{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}'); // WebHook Control Modul-ID
        if (count($ids) > 0) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            $found = false;

            foreach ($hooks as $index => $hook) {
                if ($hook['Hook'] == $WebHook) {
                    if ($hook['TargetID'] == $this->InstanceID) {
                        $this->SendDebug("RegisterHook", "Webhook bereits registriert: " . $WebHook, 0);
                        return;
                    }
                    $hooks[$index]['TargetID'] = $this->InstanceID;
                    $found = true;
                }
            }

            if (!$found) {
                $hooks[] = ['Hook' => $WebHook, 'TargetID' => $this->InstanceID];
                $this->SendDebug("RegisterHook", "Webhook erfolgreich registriert: " . $WebHook, 0);
            }

            IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
            IPS_ApplyChanges($ids[0]);
        } else {
            $this->SendDebug("RegisterHook", "WebHook Control Modul nicht gefunden!", 0);
        }
    }

    protected function ProcessHookData()
    {
        $this->SendDebug('WebHook', 'Daten empfangen: ' . print_r($_POST, true), 0);
    }
}

?>
