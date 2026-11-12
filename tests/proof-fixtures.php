<?php
// Shared in-memory infrastructure for real proof lifecycle tests.
namespace OCP { interface IUserSession {} }
namespace {
    require_once __DIR__ . '/../lib/Service/ValidationProofService.php';
    function proofUsers(): \OCP\IUserSession {
        return new class implements \OCP\IUserSession {
            public string $uid = 'alice';
            public function getUser(): object {
                return new class($this->uid) {
                    public function __construct(private string $uid) {}
                    public function getUID(): string { return $this->uid; }
                };
            }
        };
    }
}
