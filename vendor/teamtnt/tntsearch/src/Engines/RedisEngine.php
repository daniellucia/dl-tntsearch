<?php

namespace TeamTNT\TNTSearch\Engines;

use Exception;
use PDO;
use Predis\Client;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use TeamTNT\TNTSearch\FileReaders\FileReaderInterface;
use TeamTNT\TNTSearch\Stemmer\StemmerInterface;
use TeamTNT\TNTSearch\Support\Collection;
use TeamTNT\TNTSearch\Tokenizer\TokenizerInterface;

class RedisEngine implements EngineInterface
{
    use EngineTrait;

    public string $indexName;
    public array $config;
    public PDO $index;
    public StemmerInterface $stemmer;
    public PDO $dbh;
    public string $query;
    public bool $disableOutput = false;
    public string $primaryKey;
    protected bool $excludePrimaryKey = true;
    public bool $decodeHTMLEntities = false;
    public TokenizerInterface $tokenizer;
    public array $stopWords = [];
    public int $steps = 1000;
    public bool $inMemory = true;
    public ?FileReaderInterface $filereader = null;
    public bool $asYouType = false;
    public bool $fuzziness = false;
    public int $fuzzy_prefix_length = 2;
    public int $fuzzy_distance = 2;
    public int $maxDocs = 500;
    public Client $redis;

    public function loadConfig(array $config)
    {
        $this->config = $config;

        if (!isset($this->config['redis_host']) || !isset($this->config['redis_port'])) {
            throw new Exception('Redis host and port are not set in the configuration.');
        }

        $redisHost = $this->config['redis_host'];
        $redisPort = $this->config['redis_port'];
        $redisOptions = $this->config['redis_options'] ?? null;
        $redisScheme = $this->config['redis_scheme'] ?? "tcp";
        $redisPassword = $this->config['redis_password'] ?? null;
        $redisSSLOptions = $this->config['redis_ssl_options'] ?? null;

        $this->redis = new Client([
            'scheme' => $redisScheme,
            'host' => $redisHost,
            'port' => $redisPort,
            'password' => $redisPassword,
            'ssl' => $redisSSLOptions,
        ], $redisOptions);
    }

    /**
     * @param string $indexName
     * @return $this
     * @throws Exception
     */
    public function createIndex(string $indexName)
    {
        $this->flushIndex($indexName);

        $this->indexName = $indexName;

        if (isset($this->config['stemmer'])) {
            $this->setStemmer(new $this->config['stemmer']);
        }

        if (isset($this->config['tokenizer'])) {
            $this->setTokenizer(new $this->config['tokenizer']);
        }

        if (!$this->dbh) {
            $dbh = $this->createConnector($this->config)->connect($this->config);

            if ($dbh instanceof PDO) {
                $this->dbh = $dbh;
            }
        }

        return $this;
    }

    public function updateInfoTable(string $key, $value)
    {
        $redisKey = $this->indexName . ':info';

        $this->redis->hset($redisKey, $key, $value);
    }

    public function getValueFromInfoTable(string $value)
    {
        $redisKey = $this->indexName . ':info';
        $ret = $this->redis->hget($redisKey, $value);

        return $ret ?? null;
    }

    public function run()
    {
        if ($this->config['driver'] === 'filesystem') {
            $this->readDocumentsFromFileSystem();
            return;
        }
        $result = $this->dbh->query($this->query);

        $counter = 0;
        while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
            $counter++;

            $this->processDocument(new Collection($row));

            if ($counter % $this->steps === 0) {
                $this->info("Processed {$counter} rows");
            }
        }

        if ($counter % $this->steps !== 0) {
            $this->info("Processed {$counter} rows");
        }

        $this->updateInfoTable('total_documents', $counter);

        $this->info("Total rows {$counter}");
    }

    public function processDocument(Collection $row)
    {
        $documentId = $row->get($this->getPrimaryKey());

        if ($this->excludePrimaryKey) {
            $row->forget($this->getPrimaryKey());
        }

        $stems = $row->map(function ($columnContent) {
            if (trim((string)$columnContent) === '') {
                return [];
            }

            return $this->stemText((string)$columnContent);
        });

        $this->saveToIndex($stems, $documentId);
    }

    public function saveToIndex(Collection $stems, int $docId)
    {
        $terms = $this->saveWordlist($stems);
        $this->saveDoclist($terms, $docId);
        $this->saveHitList($stems->toArray(), $docId, $terms);
    }

    public function selectIndex(string $indexName)
    {
        $this->indexName = $indexName;
    }

    public function saveWordlist(Collection $stems)
    {
        $terms = [];

        $stems->map(function ($column) use (&$terms) {
            foreach ($column as $term) {
                if (array_key_exists($term, $terms)) {
                    $terms[$term]['num_hits']++;
                    $terms[$term]['num_docs'] = 1;
                } else {
                    $terms[$term] = [
                        'num_hits' => 1,
                        'num_docs' => 1,
                    ];
                }
            }
        });

        foreach ($terms as $key => $term) {
            // Check if the term already exists in Redis
            $redisKey = $this->indexName . ':wordlist:' . $key;
            if ($this->redis->exists($redisKey)) {
                // Term already exists, retrieve existing hits and docs values
                $existingHits = $this->redis->hget($redisKey, 'num_hits');
                $existingDocs = $this->redis->hget($redisKey, 'num_docs');

                // Increment hits and docs values
                $updatedHits = $existingHits + $term['num_hits'];
                $updatedDocs = $existingDocs + $term['num_docs'];

                // Update hits and docs values in Redis
                $this->redis->hset($redisKey, 'num_hits', $updatedHits);
                $this->redis->hset($redisKey, 'num_docs', $updatedDocs);

            } else {

                // Term doesn't exist, store initial hits and docs values in Redis
                $this->redis->hset($redisKey, 'num_hits', $term['num_hits']);
                $this->redis->hset($redisKey, 'num_docs', $term['num_docs']);
            }
        }

        return $terms;
    }

    public function saveDoclist(array $terms, int $docId)
    {
        foreach ($terms as $term => $docsHits) {
            $redisKey = "{$this->indexName}:doclist:{$term}:{$docId}";
            $this->redis->hset($redisKey, 'num_hits', $docsHits['num_hits']);
        }
    }

    public function saveHitList(array $stems, int $docId, array $termsList)
    {
    }

    public function getWordlistByKeyword(string $keyword, bool $isLastWord = false, bool $noLimit = false)
    {
        $redisKey = "{$this->indexName}:wordlist:{$keyword}";

        $return = [];

        if ($this->asYouType && $isLastWord) {

            // Perform custom sorting for as-you-type queries
            $wordlistKeys = $this->redis->keys("{$this->indexName}:wordlist:{$keyword}*");
            $wordlistKeys = array_filter($wordlistKeys, function ($key) {
                return $this->redis->exists($key);
            });

            if (!empty($wordlistKeys)) {
                // Sort the wordlist keys based on length and hits
                usort($wordlistKeys, function ($a, $b) {
                    $lengthA = strlen($this->redis->hget($a, 'term'));
                    $lengthB = strlen($this->redis->hget($b, 'term'));
                    $hitsA = $this->redis->hget($a, 'num_hits');
                    $hitsB = $this->redis->hget($b, 'num_hits');
                    if ($lengthA == $lengthB) {
                        return $hitsB <=> $hitsA;
                    }
                    return $lengthA <=> $lengthB;
                });

                $term = str_replace($this->indexName . ':wordlist:', '', $wordlistKeys[0]);
                $res = $this->redis->hgetall($wordlistKeys[0]);
                return [
                    [
                        'id' => $term,
                        'term' => $term,
                        'num_hits' => $res['num_hits'],
                        'num_docs' => $res['num_docs'],
                    ],
                ];

            }
        } else {

            $res = $this->redis->hgetall($redisKey);
            if (!empty($res)) {
                $return = [
                    [
                        'id' => $keyword,
                        'term' => $keyword,
                        'num_hits' => $res['num_hits'],
                        'num_docs' => $res['num_docs'],
                    ],
                ];
            }
        }

        if ($this->fuzziness && (!$res || $noLimit)) {
            return $this->fuzzySearch($keyword);
        }

        return $return;
    }

    public function getAllDocumentsForStrictKeyword(array $word, bool $noLimit)
    {
        $redisKey = $this->indexName . ':doclist:' . $word[0]['term'] . ":*";

        // Get all document IDs from the hash field
        $doclist = $this->redis->keys($redisKey);

        // Sort the document IDs if needed
        if (!$noLimit) {
            sort($doclist);
        }

        $documents = [];

        foreach ($doclist as $doc) {
            $parts = explode(':', $doc);
            $docId = $parts[3];

            $doclistKey = $this->indexName . ':doclist:' . $word[0]['term'] . ":" . $docId;

            $document = [
                'term_id' => $word[0]['term'],
                'doc_id' => $docId,
                'hit_count' => $this->redis->hget($doclistKey, 'num_hits'),
            ];

            $documents[] = $document;
        }

        return new Collection($documents);
    }

    public function delete(int $documentId)
    {
        // Fetch the terms associated with the given document ID from doclist
        $doclistKey = $this->indexName . ':doclist:*:' . $documentId;
        $doclistTerms = $this->redis->keys($doclistKey);

        // Track the wordlist keys to be updated and the hits count per term
        $wordlistKeysToUpdate = [];
        $termsHitsDeleted = [];

        // Track if any document ID was found and deleted
        $documentDeleted = false;

        // Remove the document ID from the associated terms in doclist
        foreach ($doclistTerms as $keyName) {

            // Remove the document ID from the hash
            $hits = $this->redis->hget($keyName, 'num_hits');

            $parts = explode(':', $keyName);
            $term = $parts[2];

            // Add the wordlist key to the update list
            $wordlistKeysToUpdate[] = $this->indexName . ':wordlist:' . $term;

            if (!isset($termsHitsDeleted[$term])) {
                $termsHitsDeleted[$term] = $hits;
            } else {
                $termsHitsDeleted[$term] += $hits;
            }
            $documentDeleted = true;
        }

        // If no document was found and deleted, return early
        if (!$documentDeleted) {
            return;
        }

        // Update the document count and hits count in the wordlist keys
        foreach ($wordlistKeysToUpdate as $wordlistKey) {
            $termKey = str_replace($this->indexName . ':wordlist:', '', $wordlistKey);

            $this->redis->hincrby($wordlistKey, 'num_docs', -1);
            $this->redis->hincrby($wordlistKey, 'num_hits', -$termsHitsDeleted[$termKey]);

            $docsCount = $this->redis->hget($wordlistKey, 'num_docs');

            if ($docsCount == 0) {
                $this->redis->del($wordlistKey);
            }
        }

        // Update the total_documents key in the info table
        $totalDocumentsKey = $this->indexName . ':info';
        $this->redis->hincrby($totalDocumentsKey, 'total_documents', -1);
    }

    /**
     * @return int
     */
    public function totalDocumentsInCollection()
    {
        return $this->getValueFromInfoTable('total_documents');
    }

    public function getWordFromWordList(string $word)
    {
        $word = strtolower($word);
        $redisKey = $this->indexName . ':wordlist:' . $word;
        $result = $this->redis->hgetall($redisKey);

        if (!empty($result)) {
            return [
                'id' => $word,
                'term' => $word,
                'num_hits' => $result['num_hits'],
                'num_docs' => $result['num_docs'],
            ];
        }

        return null;
    }

    /**
     * @param $keyword
     *
     * @return array
     */
    public function fuzzySearch(string $keyword)
    {
        $prefix = mb_substr($keyword, 0, $this->fuzzy_prefix_length);
        $redisKeyPattern = $this->indexName . ':wordlist:' . $prefix . '*';
        $wordlistKeys = $this->redis->keys($redisKeyPattern);
        $resultSet = [];
        foreach ($wordlistKeys as $wordlistKey) {
            $members = $this->redis->hgetall($wordlistKey);
            $term = str_replace([$this->indexName . ':wordlist:', ':'], '', $wordlistKey);

            $distance = levenshtein($term, $keyword);
            if ($distance <= $this->fuzzy_distance) {
                $resultSet[] = [
                    'term' => $term,
                    'distance' => $distance,
                    'num_hits' => $members['num_hits'],
                    'num_docs' => $members['num_docs'],
                ];
            }
        }

        // Sort the result set by distance and then by num_hits
        usort($resultSet, function ($a, $b) {
            if ($a['distance'] === $b['distance']) {
                return $b['num_hits'] <=> $a['num_hits'];
            }
            return $a['distance'] <=> $b['distance'];
        });
        return $resultSet;
    }

    public function getAllDocumentsForFuzzyKeyword(array $words, bool $noLimit)
    {
        $docs = [];
        foreach ($words as $word) {
            $doclistKey = $this->indexName . ':doclist:' . $word['term'] . ":*";

            $doclist = $this->redis->keys($doclistKey);
            foreach ($doclist as $doc) {
                $hitCount = $this->redis->hget($doc, 'num_hits');
                $parts = explode(':', $doc);
                $docId = $parts[3];

                $docs[] = [
                    "doc_id" => $docId,
                    "hit_count" => $hitCount,
                ];
            }
        }

        if ($noLimit) {
            return new Collection($docs);
        } else {
            return new Collection(array_slice($docs, 0, $this->maxDocs));
        }
    }

    public function readDocumentsFromFileSystem()
    {
        $exclude = [];
        if (isset($this->config['exclude'])) {
            $exclude = $this->config['exclude'];
        }

        $path = realpath($this->config['location']);

        $objects = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path),
            RecursiveIteratorIterator::SELF_FIRST);
        $counter = 0;

        foreach ($objects as $name => $object) {
            $name = str_replace($path . '/', '', $name);

            if (is_callable($this->config['extension'])) {
                $includeFile = $this->config['extension']($object);
            } elseif (is_array($this->config['extension'])) {
                $includeFile = in_array($object->getExtension(), $this->config['extension']);
            } else {
                $includeFile = stringEndsWith($name, $this->config['extension']);
            }

            if ($includeFile && !in_array($name, $exclude)) {
                $counter++;
                $file = [
                    'id' => $counter,
                    'name' => $name,
                    'content' => $this->filereader->read($object),
                ];
                $fileCollection = new Collection($file);

                if (property_exists($this->filereader, 'fileFilterCallback')
                    && is_callable($this->filereader->fileFilterCallback)) {
                    $fileCollection = $fileCollection->filter($this->filereader->fileFilterCallback);
                }
                if (property_exists($this->filereader, 'fileMapCallback')
                    && is_callable($this->filereader->fileMapCallback)) {
                    $fileCollection = $fileCollection->map($this->filereader->fileMapCallback);
                }

                $this->processDocument($fileCollection);
                $redisKey = $this->indexName . ':filemap:' . $counter;
                $this->redis->hset($redisKey, 'id', $counter);
                $this->redis->hset($redisKey, 'path', $object);
                $this->info("Processed $counter $object");
            }
        }
    }

    public function getAllDocumentsForWhereKeywordNot(string $keyword, bool $noLimit = false)
    {
        $word = $this->getWordlistByKeyword($keyword);
        if (!isset($word[0])) {
            return new Collection([]);
        }

        $pattern = $this->indexName . ':doclist:*';
        $excludedKey = $this->indexName . ':doclist:' . $keyword . ":*";
        $limit = $this->maxDocs;

        // Get all doc_ids where the keyword is excluded
        $excludedDocs = $this->redis->keys($excludedKey);

        $excludedDocs = array_map(function ($doc) {
            $parts = explode(':', $doc);
            $docId = $parts[3];
            return ['doc_id' => $docId];
        }, $excludedDocs);

        // Retrieve all keys matching the pattern
        $keys = $this->redis->keys($pattern);

        // Output the keys up to the limit
        $documents = [];
        foreach (array_slice($keys, 0, $limit) as $doc) {
            $parts = explode(':', $doc);
            $docId = $parts[3];
            $documents[] = [
                'doc_id' => $docId,
            ];
        }

        // Perform a diff between all documents and excluded documents
        $filteredDocuments = array_udiff($documents, $excludedDocs, function ($doc1, $doc2) {
            return $doc1['doc_id'] <=> $doc2['doc_id'];
        });

        return new Collection($filteredDocuments);
    }

    public function flushIndex(string $indexName)
    {
        $keys = $this->redis->keys($indexName . ':*');

        foreach ($keys as $key) {
            $this->redis->del($key);
        }
    }
}
