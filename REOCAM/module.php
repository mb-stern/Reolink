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
        $rawData = file_get_contents("php://input");
        if (!empty($rawData)) {
            $data = json_decode($rawData, true);
            if (is_array($data)) {
                $categoryID = $this->createOrGetCategory("WebhookData");
                foreach ($data as $key => $value) {
                    if (is_array($value) || is_object($value)) {
                        foreach ($value as $subKey => $subValue) {
                            if ($subKey === 'alarmTime') {
                                $dateTime = new DateTime($subValue);
                                $dateTime->setTimezone(new DateTimeZone('Europe/Berlin'));
                                $formattedAlarmTime = $dateTime->format('Y-m-d H:i:s');
                                $this->setVariable($categoryID, $subKey, $formattedAlarmTime);
                            } else {
                                $this->setVariable($categoryID, $subKey, $subValue);
                            }
                        }
                    } else {
                        $this->setVariable($categoryID, $key, $value);
                    }
                }
            }
        } else {
            IPS_LogMessage("Reolink", "Keine rohen Daten empfangen oder der Datenstrom ist leer.");
        }
    }

    private function createOrGetCategory($name)
    {
        $categoryID = @IPS_GetObjectIDByIdent($name, $this->InstanceID);
        if ($categoryID === false) {
            $categoryID = IPS_CreateCategory();
            IPS_SetName($categoryID, $name);
            IPS_SetIdent($categoryID, $name);
            IPS_SetParent($categoryID, $this->InstanceID);
        }
        return $categoryID;
    }

    private function createVariableIfNotExists($parentID, $name, $type)
    {
        $variableID = @IPS_GetObjectIDByIdent($name, $parentID);
        if ($variableID === false) {
            $variableID = IPS_CreateVariable($type);
            IPS_SetName($variableID, $name);
            IPS_SetIdent($variableID, $name);
            IPS_SetParent($variableID, $parentID);
        }
        return $variableID;
    }

    private function setVariable($parentID, $key, $value)
    {
        if (is_string($value)) {
            $varID = $this->createVariableIfNotExists($parentID, $key, 3); // String
            SetValue($varID, $value);
        } elseif (is_int($value)) {
            $varID = $this->createVariableIfNotExists($parentID, $key, 1); // Integer
            SetValue($varID, $value);
        } elseif (is_float($value)) {
            $varID = $this->createVariableIfNotExists($parentID, $key, 2); // Float
            SetValue($varID, $value);
        } elseif (is_bool($value)) {
            $varID = $this->createVariableIfNotExists($parentID, $key, 0); // Boolean
            SetValue($varID, $value);
        } elseif (is_array($value) || is_object($value)) {
            $varID = $this->createVariableIfNotExists($parentID, $key, 3); // JSON-String
            SetValue($varID, json_encode($value));
        } else {
            $varID = $this->createVariableIfNotExists($parentID, $key, 3);
            SetValue($varID, (string)$value);
        }
    }
}

?>
