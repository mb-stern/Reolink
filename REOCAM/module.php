<?php

class Reolink extends IPSModule
{
    private $hook = 'reolink';

    public function Create()
    {
        // Diese Zeile nicht entfernen
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
        // WebHook Control Modul-ID
        $ids = IPS_GetInstanceListByModuleID("{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}");
        if (count($ids) > 0) {
            $hookInstanceID = $ids[0];

            // Ein Skript für den Webhook erstellen, falls es noch nicht existiert
            $scriptID = @IPS_GetObjectIDByIdent("ReolinkHookHandler", $this->InstanceID);
            if ($scriptID === false) {
                $scriptID = IPS_CreateScript(0); // 0 = PHP-Skript
                IPS_SetParent($scriptID, $this->InstanceID);
                IPS_SetIdent($scriptID, "ReolinkHookHandler");
                IPS_SetName($scriptID, "Reolink Webhook Handler");

                // Skriptinhalt festlegen, um den Webhook zu verarbeiten
                $scriptContent = '<?php Reolink_ProcessHookData($_IPS["TARGET"]);';
                IPS_SetScriptContent($scriptID, $scriptContent);
            }

            // Verknüpfung des Webhooks mit dem erstellten Skript
            IPS_SetProperty($hookInstanceID, 'Hook', $WebHook);
            IPS_SetProperty($hookInstanceID, 'TargetID', $scriptID);
            IPS_ApplyChanges($hookInstanceID);

            $this->SendDebug("RegisterHook", "Webhook erfolgreich registriert: " . $WebHook, 0);
        } else {
            $this->SendDebug("RegisterHook", "WebHook Control Modul nicht gefunden!", 0);
        }
    }

    public function ProcessHookData()
    {
        $this->SendDebug("WebHook", "Daten empfangen: " . print_r($_POST, true), 0);
    }
}

?>
