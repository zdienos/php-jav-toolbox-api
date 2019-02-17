<?php

namespace App\Command;

use App\Entity\Title;
use App\Event\VideoFileFoundEvent;
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

class JavCleanupCommand extends Command
{
    protected static $defaultName = 'jav:cleanup';

    /**
     * @var ConsoleSectionOutput
     */
    private $progressSection;

    private $entityManager;

    public function __construct(
        EntityManagerInterface $entityManager,
        ?string $name = null
    )
    {
        $this->entityManager = $entityManager;
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
        $io = new SymfonyStyle($input, $output);

        if($output instanceof ConsoleOutput) {
            /** @var ConsoleOutput $output */

            $tableSection = $output->section();
            $this->progressSection = $progressSection = $output->section();

            $progressSection->writeln('Looking up inconsistend files in database');
            $brokenTitles = $this->entityManager->getRepository(Title::class)->findWithBrokenFiles();
            $brokenTitlesCount = count($brokenTitles);
            $progressSection->overwrite(sprintf('Found %d inconsistent files in database', $brokenTitlesCount));

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
                    $progressSection->overwrite(sprintf('%d/%d Processing %s', $i, $brokenTitlesCount, $title->getCatalognumber()));
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

                $progressSection->overwrite('Rendering table');

                $table->setFooterTitle(sprintf('Titles %d  Size %d bytes', count($brokenTitles), $collectiveSize));
                $table->render();

                $progressSection->overwrite('Complete');
            } else {
                $io->success('No broken titles found');
            }
        }
    }

    private function setEventListeners(
        ConsoleSectionOutput $sectionOutput,
        EventDispatcherInterface $eventDispatcher
    ) {
        $eventDispatcher->addListener(VideoFileFoundEvent::NAME, function(VideoFileFoundEvent $event) {
            $this->progressSection->overwrite(
                sprintf(
                    'Found videofile: %s',
                    $event->getFile()->getPathname()
                )
            );
        });
    }
}
