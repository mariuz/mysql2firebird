#!/usr/bin/php
<?php
// Copyright (c) 2011 Milan Babuskov (mbabuskov@yahoo.com)
// This software is released under GNU GPL licence version 2, please see the
// file COPYING for details

if ($argc != 2)
{
    exit("\nUsage: mysql2firebird.php filename.sql\n");
}

// split the script into statements and process each one
$statements = explode(';', file_get_contents($argv[1]));
foreach ($statements as $s)
{
    $s = str_replace('`', '"', $s);
    $s = str_replace('ENGINE=MyISAM', '', $s);
    $s = str_replace('ENGINE=InnoDB', '', $s);
    $s = str_replace('SET SQL_MODE', '-- SET SQL_MODE', $s);

    // could be done better
    $s = str_replace('DEFAULT CHARSET=utf8', '', $s);
    $s = str_replace('COLLATE=utf8_unicode_ci', '', $s);
    $s = str_replace('collate utf8_unicode_ci', '', $s);
    $s = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TABLE', $s);
    $s = str_replace(' unsigned', ' /* WARNING: unsigned */', $s);

    // convert datatypes
    $s = str_replace(' int(11)', ' integer', $s);
    $s = str_replace(' int(10)', ' integer', $s);
    $s = str_replace(' tinyint(4)', ' smallint /* WARNING: tinyint */', $s);
    $s = str_replace(' mediumint(4)', ' integer /* WARNING: mediumint */', $s);
    $s = str_replace(' mediumint(8)', ' integer /* WARNING: mediumint */', $s);
    $s = str_replace(' smallint(6)', ' smallint', $s);
    $s = str_replace(' bigint(20)', ' bigint', $s);
    $s = str_replace(' datetime', ' timestamp', $s);
    $s = str_replace(' longtext', ' varchar(8000)', $s);
    $s = str_replace(' text', ' varchar(8000)', $s);

    // break statements into lines
    $sp = explode("\n", $s);
    $ac_field = false;
    $ac_value = 0;
    $keys = array();
    $lines = array();
    foreach ($sp as $line)
    {
        if (strpos($line, 'NOT NULL') !== false)
        {
            $line = trim(str_replace('NOT NULL', '', $line));
            $tline = trim($line, ',');
            if ($tline == $line)
                $line .= 'NOT NULL';
            else
                $line = $tline.' NOT NULL,';
        }

        // break line into fields
        $fields = explode(' ', trim($line));
        if (strpos($line, 'CREATE TABLE') !== false)
        {
            $tablename = trim($fields[2]);
            $table = str_replace('"', '', $tablename);
        }

        if (strpos($line, 'auto_increment') !== false)  // extract field name
            $ac_field = trim($fields[0]);
        $line = str_replace('auto_increment', '', $line);

        $fields = explode(' ', trim($line));
        if ($fields[0] == 'KEY')
        {
            $k = "    create index idx_".$table.'_'.count($keys)." on $tablename ";
            unset($fields[1]);
            unset($fields[0]);
            $keys[] = trim($k.join(' ', $fields), ',');
            continue;
        }
        foreach ($fields as $ix => $f)
        {
            if (strpos($f, 'AUTO_INCREMENT=') !== false)
            {
                $p = explode('=', trim($f));
                $ac_value = $p[1];
                $fields[$ix] = '';
            }

            if (trim($f) == 'DEFAULT' && strpos($fields[$ix+1], 'CHARSET=') !== false)
            {
                $fields[$ix] = '';
                $fields[$ix+1] = '';
            }

            if (trim($f) == 'UNIQUE' && trim($fields[$ix+1]) == 'KEY')
            {
                $fields[$ix+1] = '';
                $fields[$ix+2] = '';
            }
            
            // set default values for firebird
			if (strtoupper(trim($f)) == 'DEFAULT' && strpos($fields[$ix+1], 'CHARSET=') === false)
			{
				$fields[$ix+1] = str_replace(array(',','/'),'',$fields[$ix+1]);
				$set_default_queries[] = "alter table ".$tablename." alter ".trim($fields[0])." set default '".$fields[$ix+1]."';";
				
				$fields[$ix] = '';
				$fields[$ix+1] = '';
			}
        }
        $lines[] = trim(join(' ', $fields));
    }

    // remove trailing comma at the end of create table
    foreach ($lines as $ix => $l)
        if ($ix > 0 && substr($l, 0, 1) == ')')    // end of CREATE TABLE
            $lines[$ix-1] = trim($lines[$ix-1], ',');

    $s = join("\n", $lines);
    if (trim($s) != '')
        echo $s.';';

    foreach ($keys as $k)
        echo "\n$k;";

    if ($ac_field !== false)
    {
        echo "
    create generator gen_$table;
    set generator gen_$table to $ac_value;
    set term !! ;
        create trigger t_$table for $tablename before insert position 0
        as begin
            if (new.$ac_field is null) then
                new.$ac_field = gen_id(gen_$table, 1);
            RDB\$SET_CONTEXT('USER_SESSION', 'LAST_INSERT_ID', new.$ac_field);
        end!!
    set term ; !!
        ";
    }
}
echo "
set term !! ;
CREATE PROCEDURE LAST_INSERT_ID RETURNING (ID BIGINT) AS
BEGIN
id = RDB\$GET_CONTEXT('USER_SESSION', 'LAST_INSERT_ID');
suspend;
END
set term ; !!
";

if (isset($set_default_queries))
    $output .= implode("\n",$set_default_queries);
