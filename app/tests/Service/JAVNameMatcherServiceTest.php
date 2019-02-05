<?php
namespace App\Tests\Service;

use App\Entity\Inode;
use App\Entity\JavFile;
use App\Entity\Title;
use App\Exception\JavIDExtractionException;
use App\Repository\JavFileRepository;
use App\Service\JAVNameMatcherService;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\EntityManagerInterface;
use org\bovigo\vfs\content\LargeFileContent;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class JAVNameMatcherServiceTest extends TestCase
{
    private $rootFs;

    /**
     * @var MockObject|LoggerInterface
     */
    private $logger;

    /**
     * @var MockObject|EventDispatcherInterface
     */
    private $eventDispatcher;

    /**
     * @var MockObject|EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var MockObject|ObjectRepository
     */
    private $repository;

    /**
     * @var MockObject|JavFileRepository
     */
    private $javFileRepository;

    /**
     * @var JAVNameMatcherService
     */
    private $service;

    protected function setUp()
    {
        $this->logger            = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher   = $this->createMock(EventDispatcherInterface::class);
        $this->entityManager     = $this->createMock(EntityManagerInterface::class);
        $this->repository        = $this->createMock(ObjectRepository::class);
        $this->javFileRepository = $this->createMock(JavFileRepository::class);

        $this->service          = new JAVNameMatcherService($this->logger, $this->eventDispatcher, $this->entityManager);

        $this->rootFs           = vfsStream::setup('testDir');

        parent::setUp(); // TODO: Change the autogenerated stub
    }

    /**
     * @test
     */
    public function willMatchNameForNewEntry()
    {
        $testFile = vfsStream::newFile('ABC-123.mkv')
            ->withContent(LargeFileContent::withKilobytes(9001))
            ->at($this->rootFs);

        $finfo = new \SplFileInfo($testFile->url());

        $this->entityManager->expects($this->exactly(3))
            ->method('getRepository')
            ->withConsecutive(
                [Title::class],
                [JavFile::class],
                [Inode::class]
            )
            ->willReturnOnConsecutiveCalls(
                $this->repository,
                $this->javFileRepository,
                $this->repository
            );

        $this->repository->expects($this->once())
            ->method('findOneBy')
            ->with(['catalognumber' => 'ABC-123'])
            ->willReturn(false);

        $this->javFileRepository->expects($this->once())
            ->method('findOneByFileInfo')
            ->with($finfo)
            ->willReturn(false);

        $this->repository->expects($this->once())
            ->method('find')
            ->willReturn(false);

        $result = $this->service->extractIDFromFileInfo($finfo);

        $this->assertInstanceOf(Title::class, $result);
        $this->assertEquals('ABC-123', $result->getCatalognumber());
        /** @var JavFile $javFile */
        $javFile = $result->getFiles()->first();
        $this->assertInstanceOf(JavFile::class, $javFile);
        $this->assertEquals($testFile->url(), $javFile->getPath());
        $this->assertEquals('ABC-123.mkv', $javFile->getFilename());
        $this->assertEquals(1, $javFile->getPart());
    }

    /**
     * @test
     * @expectedException App\Exception\JavIDExtractionException
     */
    public function willThrowExceptionIfNoIDCanBeExtracted()
    {
        $testFile = vfsStream::newFile('no id here.mkv')
            ->withContent(LargeFileContent::withKilobytes(9001))
            ->at($this->rootFs);

        $finfo = new \SplFileInfo($testFile->url());

        try {
            $this->service->extractIDFromFileInfo($finfo);
        } catch (JavIDExtractionException $exception) {
            $this->assertEquals($finfo, $exception->getFileinfo());
            $this->assertInstanceOf(ArrayCollection::class, $exception->getMatchers());
            throw $exception;
        }
    }
}
