<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterMessage(0, IPS_KERNELMESSAGE); // Kernel READY Nachricht abonnieren
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Wenn der Kernel bereits bereit ist, den Webhook registrieren
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterHook('/hook/reolink');
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        // Sicherstellen, dass der Kernel bereit ist
        if ($Message == IPS_KERNELMESSAGE && $Data[0] == KR_READY) {
            $this->RegisterHook('/hook/reolink');
        }
    }

    private function RegisterHook($Hook)
    {
        // WebHook Control Modul-ID suchen
        $webhookID = IPS_GetInstanceListByModuleID("{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}");
        if (count($webhookID) > 0) {
            $hookID = $webhookID[0];

            // Existierendes Hook-Skript prüfen oder neu erstellen
            $scriptID = @IPS_GetObjectIDByIdent("HookHandler", $this->InstanceID);
            if ($scriptID === false) {
                $scriptID = IPS_CreateScript(0); // PHP-Skript erstellen
                IPS_SetParent($scriptID, $this->InstanceID);
                IPS_SetIdent($scriptID, "HookHandler");
                IPS_SetName($scriptID, "Reolink Hook Handler");
                IPS_SetScriptContent($scriptID, '<?php Reolink_ProcessHookData($_IPS["TARGET"]);');
            }

            // Skript und Webhook im Debug ausgeben, ohne nicht existierende Eigenschaften zu verwenden
            $this->SendDebug("RegisterHook", "Webhook erstellt mit SkriptID: " . $scriptID, 0);
        } else {
            $this->SendDebug("RegisterHook", "WebHook Control Modul nicht gefunden!", 0);
        }
    }

    public function ProcessHookData()
    {
        $this->SendDebug("WebHook", "Daten empfangen: " . file_get_contents('php://input'), 0);
    }
}

?>
