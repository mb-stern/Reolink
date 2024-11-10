<?php

class Reolink extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterHook('/hook/reolink');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterHook('/hook/reolink');
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
    $this->SendDebug('Webhook Triggered', 'Reolink Webhook wurde ausgelöst', 0);

    // Empfange alle Daten
    $rawData = file_get_contents("php://input");
    $this->SendDebug('Raw POST Data', $rawData, 0);

    // Überprüfe GET-Parameter in der URL
    $getParams = $_GET;
    $this->SendDebug('GET Parameters', json_encode($getParams), 0);

    // Überprüfe POST-Parameter (falls verwendet)
    $postParams = $_POST;
    $this->SendDebug('POST Parameters', json_encode($postParams), 0);

    if (!empty($rawData)) {
        $data = json_decode($rawData, true);
        if (is_array($data)) {
            $this->SendDebug('Decoded JSON Data', json_encode($data), 0);
            $this->ProcessData($data);
        } else {
            $this->SendDebug('JSON Decoding Error', 'Die empfangenen Rohdaten konnten nicht als JSON decodiert werden.', 0);
        }
    } elseif (!empty($getParams)) {
        // GET-Parameter verarbeiten, falls vorhanden
        $this->SendDebug('Processing GET Parameters', json_encode($getParams), 0);
        $this->ProcessData($getParams);
    } else {
        IPS_LogMessage("Reolink", "Keine Daten empfangen oder Datenstrom ist leer.");
        $this->SendDebug("Reolink", "Keine Daten empfangen oder Datenstrom ist leer.", 0);
    }
}


    private function ProcessData($data)
    {
        foreach ($data as $key => $value) {
            if (is_array($value) || is_object($value)) {
                foreach ($value as $subKey => $subValue) {
                    if ($subKey === 'alarmTime') {
                        // Zeitstempel in richtige Zeitzone konvertieren und speichern
                        $dateTime = new DateTime($subValue);
                        $dateTime->setTimezone(new DateTimeZone('Europe/Berlin'));
                        $formattedAlarmTime = $dateTime->format('Y-m-d H:i:s');
                        $this->updateVariable($subKey, $formattedAlarmTime, 3); // String
                    } else {
                        $this->updateVariable($subKey, $subValue);
                    }
                }
            } else {
                $this->updateVariable($key, $value);
            }
        }
    }

    private function updateVariable($name, $value, $type = null)
    {
        if ($type === null) {
            // Automatische Typbestimmung
            if (is_string($value)) {
                $type = 3; // String
            } elseif (is_int($value)) {
                $type = 1; // Integer
            } elseif (is_float($value)) {
                $type = 2; // Float
            } elseif (is_bool($value)) {
                $type = 0; // Boolean
            } else {
                $type = 3; // Standardmäßig als String speichern
                $value = json_encode($value);
            }
        }

        $ident = $this->normalizeIdent($name);
        $this->RegisterVariable($ident, $name, $type);
        $this->SetValue($ident, $value);
    }

    private function normalizeIdent($name)
    {
        // Ident normalisieren, da sie bestimmte Zeichen nicht erlauben
        $ident = preg_replace('/[^a-zA-Z0-9_]/', '_', $name);
        return substr($ident, 0, 32); // Maximale Länge für Idents beträgt 32 Zeichen
    }
}

?>
