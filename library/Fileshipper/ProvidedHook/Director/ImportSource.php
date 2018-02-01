<?php

namespace Icinga\Module\Fileshipper\ProvidedHook\Director;

use DirectoryIterator;
use Icinga\Application\Config;
use Icinga\Exception\ConfigurationError;
use Icinga\Exception\IcingaException;
use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Fileshipper\Xlsx\Workbook;
use Symfony\Component\Yaml\Yaml;
use phpseclib\Net\SFTP;
use phpseclib\Crypt\RSA;

class ImportSource extends ImportSourceHook
{
    protected $db;

    protected $haveSymfonyYaml;

    public function getName()
    {
        return 'Import from files (fileshipper)';
    }

    public function fetchData()
    {
        $basedir  = $this->getSetting('basedir');
        $filename = $this->getSetting('file_name');
        $format   = $this->getSetting('file_format');

        if ($filename === '*') {
            return $this->fetchFiles($basedir, $format);
        }

        return (array) $this->fetchFile($basedir, $filename, $format);
    }

    public function listColumns()
    {
        return array_keys((array) current($this->fetchData()));
    }

    public static function addSettingsFormFields(QuickForm $form)
    {
        $form->addElement('select', 'file_format', array(
            'label'        => $form->translate('File format'),
            'description'  => $form->translate(
                'Available file formats, usually CSV, JSON, YAML and XML. Whether'
                . ' all of those are available eventually depends on various'
                . ' libraries installed on your system. Please have a look at'
                . ' the documentation in case your list is not complete.'
            ),
            'required'     => true,
            'class'        => 'autosubmit',
            'multiOptions' => $form->optionalEnum(
                static::listAvailableFormats($form)
            ),
        ));

        $format = $form->getSentOrObjectSetting('file_format');

        $form->addElement('select', 'basedir', array(
            'label'        => $form->translate('Base directoy'),
            'description'  => sprintf(
                $form->translate(
                    'This import rule will only work with files relative to this'
                    . ' directory. The content of this list depends on your'
                    . ' configuration in "%s"'
                ),
                Config::module('fileshipper', 'imports')->getConfigFile()
            ),
            'required'     => true,
            'class'        => 'autosubmit',
            'multiOptions' => $form->optionalEnum(static::listBaseDirectories()),
        ));


        if (! ($basedir = $form->getSentOrObjectSetting('basedir'))) {
            return $form;
        }

        $form->addElement('select', 'file_name', array(
            'label'        => $form->translate('File name'),
            'description'  => $form->translate(
                'Choose a file from the above directory or * to import all files'
                . ' from there at once'
            ),
            'required' => true,
            'class'    => 'autosubmit',
            'multiOptions' => $form->optionalEnum(self::enumFiles($basedir, $form)),
        ));

        $basedir = $form->getSentOrObjectSetting('basedir');
        $basename = $form->getSentOrObjectSetting('file_name');
        if ($basedir === null || $basename === null) {
            return $form;
        }

        $filename = sprintf('%s/%s', $basedir, $basename);
        switch ($format) {
            case 'csv':
                static::addCsvElements($form);
                break;

            case 'xslx':
                static::addXslxElements($form, $filename);
                break;
        }

        return $form;
    }

    protected static function addCsvElements(QuickForm $form)
    {
        $form->addElement('text', 'csv_delimiter', array(
            'label'       => $form->translate('Field delimiter'),
            'description' => $form->translate(
                'This sets the field delimiter. One character only, defaults'
                . ' to comma: ,'
            ),
            'value'       => ',',
            'required'    => true,
        ));

        $form->addElement('text', 'csv_enclosure', array(
            'label'       => $form->translate('Value enclosure'),
            'description' => $form->translate(
                'This sets the field enclosure character. One character only,'
                . ' defaults to double quote: "'
            ),
            'value'       => '"',
            'required'    => true,
        ));

        /*
        // Not configuring escape as it behaves strangely. "te""st" works fine.
        // Seems that even in case we use \, it must be "manually" removed later
        // on
        $form->addElement('text', 'csv_escape', array(
            'label'       => $form->translate('Escape character'),
            'description' => $form->translate(
                'This sets the escaping character. One character only,'
                . ' defaults to backslash: \\'
            ),
            'value'       => '\\',
            'required'    => true,
        ));
        */
    }

    protected static function addXslxElements(QuickForm $form, $filename)
    {
        $form->addElement('select', 'worksheet_addressing', array(
            'label'        => $form->translate('Choose worksheet'),
            'description'  => $form->translate('How to choose a worksheet'),
            'multiOptions' => array(
                'by_position' => $form->translate('by position'),
                'by_name'     => $form->translate('by name'),
            ),
            'value'    => 'by_position',
            'class'    => 'autosubmit',
            'required' => true,
        ));

        $addressing = $form->getSentOrObjectSetting('worksheet_addressing');
        switch ($addressing) {

            case 'by_name':
                $file = static::loadXslxFile($filename);
                $names = $file->getSheetNames();
                $names = array_combine($names, $names);
                $form->addElement('select', 'worksheet_name', array(
                    'label'    => $form->translate('Name'),
                    'required' => true,
                    'value'    => $file->getFirstSheetName(),
                    'multiOptions' => $names,
                ));
                break;

            case 'by_position':
            default:
                $form->addElement('text', 'worksheet_position', array(
                    'label'    => $form->translate('Position'),
                    'required' => true,
                    'value'    => '1',
                ));
                break;
        }
    }

    protected function fetchFiles($basedir, $format)
    {
        $result = array();
        foreach (static::listFiles($basedir) as $file) {
            $result[$file] = (object) $this->fetchFile($basedir, $file, $format);
        }

        return $result;
    }

    protected function fetchFile($basedir, $file, $format)
    {
		$config = Config::module('fileshipper', 'imports');
        $section = null;
        foreach ($config as $key => $sec) {
            if (($dir = $sec->get('basedir')) && $dir === $basedir && @is_dir($dir)) {
                $section = $sec;
                break;
            }
        }
        
        if ($section === null) {
            throw new ConfigurationError(
                'The basedir "%s" is not in the imports.ini',
                $basedir
            );
        }
        
        if ($section->get('remote')) {
            static::downloadRemoteFile($section, $basedir, $file);
        }

        $filename = $basedir . '/' . $file;

        switch ($format) {
            case 'yaml':
                return $this->readYamlFile($filename);
            case 'json':
                return $this->readJsonFile($filename);
            case 'csv':
                return $this->readCsvFile($filename);
            case 'xslx':
                return $this->readXslxFile($filename);
            case 'xml':
                libxml_disable_entity_loader(true);
                return $this->readXmlFile($filename);
            default:
                throw new ConfigurationError(
                    'Unsupported file format: %s',
                    $format
                );
        }
    }

    protected static function loadXslxFile($filename)
    {
        return new Workbook($filename);
    }

    protected function readXslxFile($filename)
    {
        $xlsx = new Workbook($filename);
        if ($this->getSetting('worksheet_addressing') === 'by_name') {
            $sheet = $xlsx->getSheetByName($this->getSetting('worksheet_name'));
        } else {
            $sheet = $xlsx->getSheet((int) $this->getSetting('worksheet_position'));
        }

        $data = $sheet->getData();

        $headers = null;
        $result = [];
        foreach ($data as $line) {
            if ($headers === null) {
                $hasValue = false;
                foreach ($line as $value) {
                    if ($value !== null) {
                        $hasValue = true;
                        break;
                    }
                    // For now, no value in the first column means this is no header
                    break;
                }
                if ($hasValue) {
                    $headers = $line;
                }

                continue;
            }

            $row = [];
            foreach ($line as $key => $val) {
                if (empty($headers[$key])) {
                    continue;
                }
                $row[$headers[$key]] = $val;
            }

            $result[] = (object) $row;
        }

        return $result;
    }

    protected function readCsvFile($filename)
    {
        $fh = fopen($filename, 'r');
        $lines = array();
        $delimiter = $this->getSetting('csv_delimiter');
        $enclosure = $this->getSetting('csv_enclosure');
        // $escape    = $this->getSetting('csv_escape');

        $headers = fgetcsv($fh, 0, $delimiter, $enclosure/*, $escape*/);
        $row = 1;
        while ($line = fgetcsv($fh, 0, $delimiter, $enclosure/*, $escape*/)) {
            if (empty($line)) {
                continue;
            }
            if (count($headers) !== count($line)) {
                throw new IcingaException(
                    'Column count in row %d does not match columns in header row',
                    $row
                );
            }

            $line = array_combine($headers, $line);
            foreach ($line as $key => & $value) {
                if ($value === '') {
                    $value = null;
                }
            }
            $lines[] = (object) $line;

            $row ++;
        }
        fclose($fh);

        return $lines;
    }

    protected function readJsonFile($filename)
    {
        $content = @file_get_contents($filename);
        if ($content === false) {
            throw new IcingaException(
                'Unable to read JSON file "%s"',
                $filename
            );
        }

        $data = @json_decode($content);
        if ($data === null) {
            throw new IcingaException(
                'Unable to load JSON data'
            );
        }

        return $data;
    }

    protected function readXmlFile($file)
    {
        $lines = array();
        $content = file_get_contents($file);
        foreach (simplexml_load_string($content) as $entry) {
            $line = null;
            $lines[] = $this->normalizeSimpleXML($entry);
        }

        return $lines;
    }

    protected function normalizeSimpleXML($obj)
    {
        $data = $obj;
        if (is_object($data)) {
            $data = (object) get_object_vars($data);
        }

        if (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = $this->normalizeSimpleXml($value);
            }
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->normalizeSimpleXml($value);
            }
        }

        return $data;
    }

    protected function readYamlFile($file)
    {
        return $this->fixYamlObjects(
            yaml_parse_file($file)
        );
    }

    protected function fixYamlObjects($what)
    {
        if (is_array($what)) {
            foreach (array_keys($what) as $key) {
                if (! is_int($key)) {
                    $what = (object) $what;
                    break;
                }
            }
        }

        if (is_array($what) || is_object($what)) {
            foreach ($what as $k => $v) {
                if (! empty($v)) {
                    if (is_object($what)) {
                        $what->$k = $this->fixYamlObjects($v);
                    } elseif (is_array($what)) {
                        $what[$k] = $this->fixYamlObjects($v);
                    }
                }
            }
        }

        return $what;
    }

    protected static function listAvailableFormats(QuickForm $form)
    {
        $formats = array(
            'csv'  => $form->translate('CSV (Comma Separated Value)'),
            'json' => $form->translate('JSON (JavaScript Object Notation)'),
        );

        if (class_exists('\\ZipArchive')) {
            $formats['xslx'] = $form->translate('XSLX (Microsoft Excel 2007+)');
        }

        if (function_exists('simplexml_load_file')) {
            $formats['xml'] = $form->translate('XML (Extensible Markup Language)');
        }

        if (function_exists('yaml_parse_file')) {
            $formats['yaml'] = $form->translate('YAML (Ain\'t Markup Language)');
        }

        return $formats;
    }

    protected static function listBaseDirectories()
    {
        $dirs = array();

        foreach (Config::module('fileshipper', 'imports') as $key => $section) {
            if (($dir = $section->get('basedir')) && @is_dir($dir)) {
                $dirs[$dir] = $key;
            }
        }

        return $dirs;
    }

    protected static function enumFiles($basedir, QuickForm $form)
    {
        return array_merge(
            array(
                '*' => sprintf('* (%s)', $form->translate('all files'))
            ),
            static::listFiles($basedir)
        );
    }

    protected static function listFiles($basedir)
    {
        $files = array();
		
		$config = Config::module('fileshipper', 'imports');
        $section = null;
        foreach ($config as $key => $sec) {
            if (($dir = $sec->get('basedir')) && $dir === $basedir && @is_dir($dir)) {
                $section = $sec;
                break;
            }
        }
        
        if ($section === null) {
            throw new ConfigurationError(
                'The basedir "%s" is not in the imports.ini',
                $basedir
            );
        }
        
        if ($section->get('remote')) {
            $files = static::listFilesRemote($section);
        } else {
            $files = static::listFilesLocal($basedir);
        }
 
        ksort($files);
 
        return $files;
    }

    protected static function listFilesLocal($basedir)
    {
        $files = array();
		
        $dir = new DirectoryIterator($basedir);
        foreach ($dir as $file) {
            if ($file->isFile()) {
                $filename = $file->getBasename();
                if ($filename[0] !== '.') {
                    $files[$filename] = $filename;
                }
            }
        }

        return $files;
    }

	protected static function listFilesRemote($configSection)
    {
        if (!isset($configSection->{'type'})) {
            throw new ConfigurationError(
                'The remote type is missing in the imports.ini'
            );
        }
        
        switch($configSection->{'type'}) {
            case 'sftp':
                return static::listFilesRemoteSftp($configSection);
                break;
            default:
                throw new ConfigurationError(
                    'Unsupported remote type: %s',
                    $configSection->{'type'}
                );
                break;
        }
    }
 
    protected static function listFilesRemoteSftp($configSection)
    {
        $files = array();
        if(!($host = $configSection->get('host'))) {
            throw new ConfigurationError(
                'The host is missing in the imports.ini'
            );
        }
        $port = $configSection->get('port', 22);
        if(!($username = $configSection->get('username'))) {
            throw new ConfigurationError(
                'The username is missing in the imports.ini'
            );
        }
        $password = $configSection->get('password', '');
        $privkeyfile = $configSection->get('privkeyfile', null);
        $passphrase = $configSection->get('passphrase', '');
        if(!($remotedir = $configSection->get('remotedir'))) {
            throw new ConfigurationError(
                'The remotedir is missing in the imports.ini'
            );
        }
        $filter = $configSection->get('filter', '.*');
		
		$sftp = new SFTP($host);
		
        if ($privkeyfile) {
			$key = new RSA();
			$key->setPassword($passphrase);
			$key->loadKey(file_get_contents($privkeyfile));
			if (!$sftp->login($username, $key)) {
				throw new IcingaException(
                    'Unable to authenticate with privkeyfile and passphrase'
                );
			}
        } else {
			if (!$sftp->login($username, $password)) {
				throw new IcingaException(
                    'Unable to authenticate with username and password'
                );
			}
        }
		
		$sftp->chdir($remotedir);
		
		foreach ($sftp->nlist() as $filename) {
            if ($filename == "." || $filename == "..")
                continue;
            if (!preg_match('/' . $filter . '/', $filename))
                continue;
            $files[$filename] = $filename;
        }
		
        return $files;
    }
    
    protected static function downloadRemoteFile($configSection, $basedir, $file)
    {
        if (!isset($configSection->{'type'})) {
            throw new ConfigurationError(
                'The remote type is missing in the imports.ini'
            );
        }
        
        switch($configSection->{'type'}) {
            case 'sftp':
                return static::downloadRemoteSftpFile($configSection, $basedir, $file);
                break;
            default:
                throw new ConfigurationError(
                    'Unsupported remote type: %s',
                    $configSection->{'type'}
                );
                break;
        }
    }
    
    protected static function downloadRemoteSftpFile($configSection, $basedir, $file)
    {
        if(!($host = $configSection->get('host'))) {
            throw new ConfigurationError(
                'The host is missing in the imports.ini'
            );
        }
        $port = $configSection->get('port', 22);
        if(!($username = $configSection->get('username'))) {
            throw new ConfigurationError(
                'The username is missing in the imports.ini'
            );
        }
        $password = $configSection->get('password', '');
        $privkeyfile = $configSection->get('privkeyfile', null);
        $passphrase = $configSection->get('passphrase', '');
        if(!($remotedir = $configSection->get('remotedir'))) {
            throw new ConfigurationError(
                'The remotedir is missing in the imports.ini'
            );
        }
		
		$sftp = new SFTP($host);
		
        if ($privkeyfile) {
			$key = new RSA();
			$key->setPassword($passphrase);
			$key->loadKey(file_get_contents($privkeyfile));
			if (!$sftp->login($username, $key)) {
				throw new IcingaException(
                    'Unable to authenticate with privkeyfile and passphrase'
                );
			}
        } else {
			if (!$sftp->login($username, $password)) {
				throw new IcingaException(
                    'Unable to authenticate with username and password'
                );
			}
        }
		
		$sftp->chdir($remotedir);
		$sftp->get($file, $basedir . '/' . $file);
		
        return true;
    }
}
