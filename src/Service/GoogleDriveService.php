<?php
namespace App\Service;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;

class GoogleDriveService
{   
    private Drive $driveService;
    
    public function __construct(string $credentialsPath)
    {
        
        $client = new Client();
        $client->setAuthConfig($credentialsPath);
        $client->addScope(Drive::DRIVE_FILE);
        
        $this->driveService = new Drive($client);
    }
    
    public function uploadCertificate(string $pdfContent, string $fileName): string
    {
        // Create file metadata
        $file = new DriveFile();
        $file->setName($fileName);
        $file->setParents(['1CN7eI9ViV9GczVs5gWzKi-e1k5Eex1TM']); // Create folder in Drive first
        
        // Upload
        $result = $this->driveService->files->create($file, [
            'data' => $pdfContent,
            'mimeType' => 'application/pdf',
            'uploadType' => 'multipart'
        ]);
        
        return $result->getId(); // Return file ID
    }
}