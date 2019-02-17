<?php

namespace App\Command;

use App\Entity\Title;
use App\Event\QualifiedVideoFileFound;
use App\Service\MediaProcessorService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class JavCleanupCommand extends SectionedCommand
{
    protected static $defaultName = 'jav:cleanup';

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var MediaProcessorService
     */
    private $mediaProcessorService;

    /**
     * @var ConsoleSectionOutput
     */
    private $cmdSection;

    public function __construct(
        EntityManagerInterface $entityManager,
        MediaProcessorService $mediaProcessorService,
        ?string $name = null
    )
    {
        $this->entityManager            = $entityManager;
        $this->mediaProcessorService    = $mediaProcessorService;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this
            ->setDescription('Will cleanup')
//            ->addOption('no-interaction', null, InputOption::VALUE_NONE | InputOption::VALUE_OPTIONAL, 'Require no user interaction, implicit yes to all')
            ->addOption('dry-run', null, InputOption::VALUE_NONE | InputOption::VALUE_OPTIONAL, 'Only print actions, do not execute')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if($output instanceof ConsoleOutput) {
            $tableSection = $output->section();
            $this->cmdSection = $output->section();
        }
        parent::execute($input, $output);
        

        $io = new SymfonyStyle($input, $output);

        if($output instanceof ConsoleOutput) {
            /** @var ConsoleOutput $output */

            $this->updateStateMessage('Looking up inconsistend files in database');
            $brokenTitles = $this->entityManager->getRepository(Title::class)->findWithBrokenFiles();
            $brokenTitlesCount = count($brokenTitles);
            $this->updateProgressOutput(sprintf('Found %d inconsistent files in database', $brokenTitlesCount));

            if ($brokenTitles) {
                $table = new Table($tableSection);
                $table->setHeaders([
                    'CatalogID',
                    'Inode',
                    'Part',
                    'Filesize',
                    'Filename',
                ]);

                $collectiveSize = 0;

                $i=1;
                /** @var Title $title */
                foreach ($brokenTitles as $title) {
                    $this->updateProgressOutput(sprintf('%d/%d Processing %s', $i, $brokenTitlesCount, $title->getCatalognumber()));
                    foreach ($title->getFiles() as $file) {
                        $tableRow = [
                            'catalog-id' => $title->getCatalognumber(),
                            'inode' => $file->getInode()->getId(),
                            'part' => $file->getPart(),
                            'filesize' => $file->getInode()->getFilesize(),
                            'filename' => $file->getFilename()
                        ];

                        $table->addRow($tableRow);

                        $collectiveSize += $file->getInode()->getFilesize();
                    }
                    $i++;
                }

                $this->updateStateMessage('Rendering table');

                $table->setFooterTitle(sprintf('Titles %d  Size %d bytes', count($brokenTitles), $collectiveSize));
                $table->render();

                $this->updateStateMessage('Confirming files marked for deletion');

                $starttime = time();
                foreach($brokenTitles as $title) {
                    $this->updateProgressOutput("Processing title {$title->getCatalognumber()}");
                    foreach($title->getFiles() as $file) {
                        $this->updateProgressOutput("Processing title {$title->getCatalognumber()}. File: {$file->getPath()}");
                        try {
                            $this->mediaProcessorService->checkHealth(
                                $file,
                                true,
                                function ($type, $buffer) use ($starttime) {
                                    if ((time() - $starttime) >= 30) {
                                        $this->entityManager->getConnection()->ping();
                                        $starttime = time();
                                    }

                                    $this->cmdSection->overwrite("FFMPEG Output: {$buffer}");
                                },
                                true);
                        } catch (\Throwable $exception) {
                            // FFMPEG failed.
                            var_dump($exception);die();
                        }
                    }
                }

                $this->updateProgressOutput('Complete');
            } else {
                $io->success('No broken titles found');
            }
        }
    }

    private function setEventListeners(
        ConsoleSectionOutput $sectionOutput,
        EventDispatcherInterface $eventDispatcher
    ) {
        $eventDispatcher->addListener(QualifiedVideoFileFound::NAME, function(QualifiedVideoFileFound $event) {
            $this->progressSection->overwrite(
                sprintf(
                    'Found videofile: %s',
                    $event->getFile()->getPathname()
                )
            );
        });
    }
}
