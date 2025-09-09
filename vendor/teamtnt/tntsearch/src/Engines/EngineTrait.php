<?php

namespace TeamTNT\TNTSearch\Engines;

use Exception;
use TeamTNT\TNTSearch\Connectors\FileSystemConnector;
use TeamTNT\TNTSearch\Connectors\MySqlConnector;
use TeamTNT\TNTSearch\Connectors\OracleDBConnector;
use TeamTNT\TNTSearch\Connectors\PostgresConnector;
use TeamTNT\TNTSearch\Connectors\SQLiteConnector;
use TeamTNT\TNTSearch\Connectors\SqlServerConnector;
use TeamTNT\TNTSearch\Stemmer\StemmerInterface;
use TeamTNT\TNTSearch\Support\Collection;
use TeamTNT\TNTSearch\Tokenizer\TokenizerInterface;

trait EngineTrait
{
    /**
     * @return string
     */
    public function getStoragePath()
    {
        return $this->config['storage'];
    }

    /**
     * @param array $config
     *
     * @return FileSystemConnector|MySqlConnector|OracleDBConnector|PostgresConnector|SQLiteConnector|SqlServerConnector
     * @throws Exception
     */
    public function createConnector(array $config)
    {
        if (!isset($config['driver'])) {
            throw new Exception('A driver must be specified.');
        }

        switch ($config['driver']) {
            case 'mysql':
            case 'mariadb':
                return new MySqlConnector;
            case 'pgsql':
                return new PostgresConnector;
            case 'sqlite':
                return new SQLiteConnector;
            case 'sqlsrv':
                return new SqlServerConnector;
            case 'filesystem':
                return new FileSystemConnector;
            case 'oracledb':
                return new OracleDBConnector;
        }
        throw new Exception("Unsupported driver [{$config['driver']}]");
    }

    public function query(string $query)
    {
        $this->query = $query;
    }

    public function disableOutput(bool $value)
    {
        $this->disableOutput = $value;
    }

    public function setStemmer(StemmerInterface $stemmer)
    {
        $this->stemmer = $stemmer;
        $this->updateInfoTable('stemmer', get_class($stemmer));
    }

    public function getPrimaryKey()
    {
        if (isset($this->primaryKey)) {
            return $this->primaryKey;
        }
        return 'id';
    }

    public function stemText(string $text)
    {
        $stemmer = $this->getStemmer();
        $words = $this->breakIntoTokens($text);
        $stems = [];
        foreach ($words as $word) {
            $stems[] = $stemmer->stem($word);
        }
        return $stems;
    }

    public function getStemmer()
    {
        return $this->stemmer;
    }

    public function breakIntoTokens(string $text)
    {
        if ($this->decodeHTMLEntities) {
            $text = html_entity_decode($text);
        }
        return $this->tokenizer->tokenize($text, $this->stopWords);
    }

    public function info(string $text)
    {
        if (!$this->disableOutput) {
            echo $text . PHP_EOL;
        }
    }

    public function setInMemory(bool $value)
    {
        $this->inMemory = $value;
    }

    public function setIndex(\PDO $index)
    {
        $this->index = $index;
    }

    /**
     * @param TokenizerInterface $tokenizer
     */
    public function setTokenizer(TokenizerInterface $tokenizer)
    {
        $this->tokenizer = $tokenizer;
        $this->updateInfoTable('tokenizer', get_class($tokenizer));
    }

    public function update(int $id, array $document)
    {
        $this->delete($id);
        $this->insert($document);
    }

    public function insert(array $document)
    {
        $this->processDocument(new Collection($document));
        $total = $this->totalDocumentsInCollection() + 1;
        $this->updateInfoTable('total_documents', $total);
    }

    public function includePrimaryKey()
    {
        $this->excludePrimaryKey = false;
    }

    public function setPrimaryKey(string $primaryKey)
    {
        $this->primaryKey = $primaryKey;
    }

    public function countWordInWordList(string $word)
    {
        $res = $this->getWordFromWordList($word);

        if ($res) {
            return $res['num_hits'];
        }
        return 0;
    }

    public function asYouType(bool $value)
    {
        $this->asYouType = $value;
    }

    public function fuzziness(bool $value)
    {
        $this->fuzziness = $value;
    }

    public function setLanguage(string $language = 'no')
    {
        $class = 'TeamTNT\\TNTSearch\\Stemmer\\' . ucfirst(strtolower($language)) . 'Stemmer';

        if (!class_exists($class)) {
            throw new Exception("Language stemmer for [{$language}] does not exist.");
        }

        if (!is_a($class, StemmerInterface::class, true)) {
            throw new Exception("Language stemmer for [{$language}] does not extend Stemmer interface.");
        }

        $this->setStemmer(new $class);
    }

    public function setDatabaseHandle(\PDO $dbh)
    {
        $this->dbh = $dbh;
    }
}
