<?php

class parser
{
    
    protected $skipNext = false;

    protected $tableName = '';

    protected $queryArray = [];

    protected $keyArray = [];

    protected $foreignKey = [];
    
    protected $uniqueKey = [];


    public function parse($sql)
    {
        $this->tableName = $this->parseTableName($sql);
        $vars = $this->getTableVars($sql);

        $res = '';

        foreach ($vars as $var) {
            $parts = explode(' ', $var);
            $this->createScriptFromArray($parts);
        }

        $res .= $this->createTable();

        foreach ($this->queryArray as $key=>$value) {
            $res .= $this->createQueryFromArray($value);
        }

        foreach ($this->keyArray as $key) {
            $res .= $this->createQueryFromKey($key);
        }

        foreach ($this->foreignKey as $foreign) {
            $res .= $this->createQueryFromForeign($foreign);
        }

        $res .= $this->createQueryFromUnique($this->uniqueKey);

        $res .= $this->createEndPart();

        return $res;

    }

    protected function createTable()
    {
        return '
    protected function create'.$this->tableName.'($installer) 
    {
        $table = $installer->getConnection()->newTable(
            $installer->getTable(\''.$this->tableName.'\')
        )';
    }

    protected function createEndPart()
    {
        return ';
        $installer->getConnection()->createTable($table);
    }';
    }

    protected function createQueryFromKey($key)
    {
        return '->addIndex(
            $installer->getIdxName(\''.$this->tableName.'\', [\''.$key.'\']),
            [\''.$key.'\']
        )';
    }
    
    protected function createQueryFromUnique($keys)
    {
        if (count($keys)>0)
        {
            return '->addIndex(
                $installer->getIdxName(
                    \''.$this->tableName.'\',
                    [\''.implode('\',\'', $keys).'\'],
                    \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE
                ),
                [\''.implode('\',\'', $keys).'\'],
                [\'type\' => \Magento\Framework\DB\Adapter\AdapterInterface::INDEX_TYPE_UNIQUE]
            )';
        }
        return '';
    }

    protected function createQueryFromForeign($foreign)
    {
        $action = '';
        switch (str_replace(',', '', trim($foreign[4]))) {
            case 'ON DELETE NO ACTION':
                $action = 'ACTION_NO_ACTION';
                break;
            case 'ON DELETE CASCADE':
                $action = 'ACTION_CASCADE';
                break;
            case 'ON DELETE SET NULL':
                $action = 'ACTION_SET_NULL';
                break;
            case 'ON DELETE SET DEFAULT':
                $action = 'ACTION_SET_DEFAULT';
                break;
            case 'ON DELETE RESTRICT':
                $action = 'ACTION_RESTRICT';
                break;
        }
        return '->addForeignKey(
            $installer->getFkName(\''.$this->tableName.'\', \''.$foreign[1].'\', \''.$foreign[2].'\', \''.$foreign[3].'\'),
            \''.$foreign[1].'\',
            $installer->getTable(\''.$foreign[2].'\'),
            \''.$foreign[3].'\',
            \Magento\Framework\DB\Ddl\Table::'.$action.'
        )
        ';
    }

    protected function parseTableName($sql)
    {
        preg_match('@CREATE TABLE.+?`(.+?)`@si', $sql, $tableName);
        return $tableName[1];
    }

    protected function getTableVars($sql)
    {
        preg_match('@CREATE TABLE.+?\((.*)ENGINE\=@si', $sql, $varSector);
        $varSector[1] = str_replace("\r", '', $varSector[1]);
        $vars = explode("\n", $varSector[1]);
        return $vars;
    }

    protected function createScriptFromArray($parts)
    {
        $this->getParamsArray($parts);
    }

    protected function createQueryFromArray($sqlArray)
    {
        $stringOptions = [];
        foreach ($sqlArray['options'] as $option) {
            $stringOptions[] = '\''.key($option).'\' => '.str_replace(',', '', (string)$option[key($option)]) ;
        }
        $stringOptions = implode(', ', $stringOptions);

        if (!$sqlArray['name'] || !$sqlArray['type']['type']) return '';
        
        $name = '->addColumn(
            \''.$sqlArray['name'].'\',
            '.$sqlArray['type']['type'].',
            '.$sqlArray['type']['length'].',
            ['.$stringOptions.']
        )';

        return $name;
    }

    protected function getParamsArray($parts)
    {
        $result = [];
        foreach ($parts as $key=>$part) {
            if ($this->skipNext) {
                $this->skipNext = false;
                continue;
            }

            $part = trim($part);

            if (strpos($part,'`')!==FALSE) {
                $part = str_replace('`', '', $part);
                $result['name'] = $part;

                $typeLength = explode('(',$parts[$key+1]);

                $result['type'] = $this->getRecordType(
                    $typeLength[0],
                    isset($typeLength[1]) ? str_replace(')', '', $typeLength[1]) : ''
                );
            }
            
            switch($part){
                case 'NOT':
                    if (isset($parts[$key+1]) && $parts[$key+1]=='NULL') {
                        $result['options'][] = ['nullable' => 'false'];
                        $this->skipNext = true;
                        break;
                    }
                case 'AUTO_INCREMENT':
                    $result['options'][] = ['identity' => 'true'];
                    break;
                case 'unsigned':
                    $result['options'][] = ['unsigned' => 'true'];
                    break;
                case 'NULL':
                    $result['options'][] = ['nullable' => 'true'];
                    break;
                case 'DEFAULT':
                    $this->skipNext = true;
                    if (isset($parts[$key+1])) {

                        if (
                            $result['type']['type'] == '\Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP' &&
                            $parts[$key+1] != 'CURRENT_TIMESTAMP'
                        ) {
                            $result['options'][] = ['default' => trim($parts[$key+1]).$parts[$key+2] ];
                        } elseif(strpos($parts[$key+1], "'") === false) {
                            $result['options'][] = ['default' => "'".trim($parts[$key+1])."'"];
                        } else {
                            $result['options'][] = ['default' => trim($parts[$key+1])];
                        }

                        $this->skipNext = true;
                        break;
                    }
                    break;

            }
        }

        if (!isset($result['type']['type'])) {
            $this->getKeys($parts);
        }

        $this->queryArray[$result['name']] = $result;

        return $result;
    }

    protected function defaultTimestamp()
    {
        
    }

    protected function getKeys($parts)
    {
        foreach ($parts as $key=>$part) {
            if (strpos($part, 'PRIMARY')!==false && $parts[$key+1]=='KEY') {
                preg_match('@`(.+?)`@',$parts[$key+2], $keyName);
                $this->queryArray[$keyName[1]]['options'][] = ['primary' => 'true'];
                return;
            }

            if (strpos($part, 'UNIQUE')!==false && $parts[$key+1]=='KEY') {
                $string = implode(' ', $parts);
                preg_match_all('@`(.+?)`@', $string, $keyName);
                unset($keyName[1][0]);
                $this->uniqueKey = $keyName[1];
            }

            if(strpos($part, 'KEY')!==false) {
                preg_match('@`(.+?)`@',$parts[$key+2], $keyName);
                $this->keyArray[] = $keyName[1];
                return;
            }

            if(strpos($part, 'CONSTRAINT')!==false) {
                $string = implode(' ', $parts);
                preg_match('@FOREIGN KEY \(`(.+?)`\) REFERENCES `(.+?)` \(`(.+?)`\) (.*)@si', $string, $foreign);
                $this->foreignKey[] = $foreign;
                return;
            }
        }
    }

    protected function getRecordType($type, $length)
    {
        $result = [];
        switch($type) {
            case 'varchar':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_TEXT';
                $result['length'] = $length;
                break;
            case 'smallint':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_SMALLINT';
                $result['length'] = $length;
                break;
            case 'tinyint':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_BOOLEAN';
                $result['length'] = $length;
                break;
            case 'int':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_INTEGER';
                $result['length'] = $length;
                break;
            case 'decimal':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_DECIMAL';
                $result['length'] = "[$length]";
                break;
            case 'timestamp':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_TIMESTAMP';
                break;
            case 'tinyblob':
            case 'blob':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_BLOB';
                break;
            case 'mediumblob':
            case 'longblob':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_VARBINARY';
                break;
            case 'float':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_FLOAT';
                break;
            case 'bigint':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_BIGINT';
                break;
            case 'datetime':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_DATETIME';
                break;
            case 'date':
                $result['type'] = '\Magento\Framework\DB\Ddl\Table::TYPE_DATE';
                break;
        }
        if (!isset($result['length'])) $result['length'] = 'null';
        return $result;
    }


}


if ($_POST['sql']) {
    $parser = new parser();
    $result = $parser->parse($_POST['sql']);
}
?>

<form action="index.php" method="post">
    <textarea name="sql"></textarea>
    <button type="submit">Convert</button>
    <?php
        if (isset($result)) {
            echo "<pre name=\"result\">$result</pre>";
        }
    ?>
</form>
