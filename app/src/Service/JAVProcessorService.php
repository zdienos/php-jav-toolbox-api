<?php
namespace App\Service;

use App\Entity\Inode;
use App\Entity\Title;
use App\Entity\JavFile;
use App\Exception\PreProcessFileException;
use App\Message\CheckVideoMessage;
use App\Message\GetVideoMetadataMessage;
use App\Message\ProcessFileMessage;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use SplFileInfo;
use Symfony\Component\Messenger\MessageBusInterface;
use App\Service\FilenameParser;

/**
 * Class JAVProcessorService
 *
 * Service which processes videofiles which could contain JAV.
 *
 * It processes filenames and tries to extract JAV Titles.
 * Calculate hashes to create a fingerprint
 *
 * @package App\Service
 */
class JAVProcessorService
{
    static $blacklistnames = [
        'hentaikuindo'
    ];

    const LOG_BLACKLIST_NAME  = 'Filename contains blacklisted string';
    const LOG_UNKNOWN_JAVJACK = 'Unknown JAVJACK file detected';

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var EventDispatcherInterface
     */
    private $dispatcher;

    /**
     * @var ArrayCollection
     */
    private $titles;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var string
     */
    private $thumbnailDirectory;

    /**
     * @var string
     */
    private $mtConfigPath;

    /**
     * @var MediaProcessorService
     */
    private $mediaProcessorService;

    /**
     * @var JAVNameMatcherService
     */
    private $javNameMatcherService;

    /**
     * @var MessageBusInterface
     */
    private $messageBus;

    public function __construct(
        LoggerInterface $logger,
        EventDispatcherInterface $dispatcher,
        EntityManagerInterface $entityManager,
        MediaProcessorService $mediaProcessorService,
        MessageBusInterface $messageBus,
        JAVNameMatcherService $javNameMatcherService,
        $javToolboxMediaThumbDirectory,
        $javToolboxMtConfigPath
    )
    {
        $this->logger                   = $logger;
        $this->dispatcher               = $dispatcher;
        $this->entityManager            = $entityManager;
        $this->mediaProcessorService    = $mediaProcessorService;
        $this->messageBus               = $messageBus;
        $this->javNameMatcherService    = $javNameMatcherService;

        $this->titles                   = new ArrayCollection();

        $this->thumbnailDirectory       = $javToolboxMediaThumbDirectory;
        $this->mtConfigPath             = $javToolboxMtConfigPath;
    }

    public function processFile(JavFile $file)
    {
        $this->logger->info('PROCESSING FILE '. $file->getFilename());

        $this->logger->debug('Dispatching message',[
            'message' => 'ProcessFileMessage',
            'id'      => $file->getId(),
            'path'    => $file->getPath()
        ]);

        $this->messageBus->dispatch(new ProcessFileMessage($file->getId()));
    }

    public function getMetadata(JavFile $file, bool $refresh = true) {
        $this->logger->notice('Dispatching message',[
            'path'    => $file->getPath(),
            'refresh' => $refresh
        ]);

        $this->messageBus->dispatch(new GetVideoMetadataMessage($file->getId()));
    }

    public function checkJAVFilesConsistency(Title $title, bool $force = false)
    {
        /** @var JavFile $javFile */
        foreach($title->getFiles() as $javFile)
        {
            if(!$javFile->getInode()->isChecked()) {
                $this->checkVideoConsistency($javFile, true, $force);
            }
        }
    }

    public function checkVideoConsistency(JavFile $file, bool $strict = true, bool $force = false)
    {
        if(!$this->entityManager->contains($file)) {
            $this->entityManager->persist($file);
        }

        $this->logger->notice('Dispatching message',[
            'path'   => $file->getPath(),
            'strict' => $strict,
            'force'  => $force
        ]);
        $this->messageBus->dispatch(new CheckVideoMessage($file->getId()));
    }

    private function fileExists(SplFileInfo $fileInfo)
    {
        if($this->entityManager->getRepository(Inode::class)->exists($fileInfo->getInode())) {
            return (bool) $this->entityManager->getRepository(JavFile::class)->findOneByFileInfo($fileInfo);
        }

        return false;
    }

    /**
     * @param SplFileInfo $file
     *
     * @todo lower complexity. This is a mess
     */
    public function preProcessFile(SplFileInfo $file)
    {
        if($this->fileExists($file)) {
            $this->logger->debug('File already exists', [
                'path' => $file->getPathname()
            ]);

            $this->processFile($this->entityManager->getRepository(JavFile::class)->findOneByFileInfo($file));
        } else {
            $javTitleInfo = $this->extractIDFromFilename($file);

//            try {
                /** @var \App\Entity\JavFile $javFile */
                $javFile = $javTitleInfo->getFiles()->first();

                if (!self::shouldProcessFile($javFile, $this->logger)) {
                    $this->logger->warning("JAVFILE NOT VALID. SKIPPING {$javFile->getFilename()}");
                    return;
                }

                $javFile->setPath($file->getPathname());
                /** @var Inode $inode */
                $inode = $this->entityManager->getRepository(Inode::class)->find($file->getInode());

                if (!$inode) {
                    $this->logger->debug('Inode entry not found, creating one', [
                        'path' => $file->getPathname(),
                        'inode' => $file->getInode()
                    ]);
                    $inode = (new Inode)->setId($file->getInode());
                    $inode->setFilesize($file->getSize());
                }

                $javFile->setInode($inode);

                /** @var Title $title */
                $title = $this->entityManager
                    ->getRepository(Title::class)
                    ->findOneBy(['catalognumber' => $javTitleInfo->getCatalognumber()]);

                if (!$title) {
                    $this->logger->notice('New title', [
                        'catalog-number' => $javTitleInfo->getCatalognumber(),
                        'filename' => $file->getFilename()
                    ]);
                    $title = $javTitleInfo;
                    $this->entityManager->merge($title);
                }
                $javFile->setTitle($title);

                $this->entityManager->merge($javFile);
                $this->entityManager->flush();

                $this->processFile($javFile);
                $this->logger->info('STORED TITLE: ' . $title->getCatalognumber());
//            } catch (\Exception $exception) {
//                $this->logger->error($exception->getMessage(), [
//                    'javfile' => [
//                        'catalog' => $javTitleInfo->getCatalognumber(),
//                        'path' => $javFile->getPath()
//                    ]
//                ]);
//            }
        }
    }

    public static function shouldProcessFile(JavFile $javFile, LoggerInterface $logger)
    {
        $fileName = trim(pathinfo($javFile->getFilename(), PATHINFO_FILENAME));

        if(ctype_xdigit($fileName) || $fileName === 'videoplayback') {
            $logger->warning(self::LOG_UNKNOWN_JAVJACK);
            return false;
        }

        foreach(self::$blacklistnames as $blacklistname) {
            if(stripos($javFile->getFilename(), $blacklistname) !== FALSE) {
                $logger->warning(self::LOG_BLACKLIST_NAME);
                return false;
            }
        }

        return true;
    }

    public function extractIDFromFilename(SplFileInfo $fileInfo): Title
    {
        return $this->javNameMatcherService->extractIDFromFileInfo($fileInfo);
    }

    public function filenameContainsID(SplFileInfo $filename): bool
    {
        return $this->extractIDFromFilename($filename) instanceof Title;
    }
}
