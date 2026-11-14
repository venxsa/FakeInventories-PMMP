<?php

declare(strict_types=1);

namespace fakeinventories\listeners\packet;

use fakeinventories\Main;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\scheduler\ClosureTask;

class DataPacketReceiveListener implements Listener {

    /**
     * @param DataPacketReceiveEvent $e
     */
    public function fakeInventory(DataPacketReceiveEvent $e) : void {
        $player = $e->getOrigin()->getPlayer();
        $packet = $e->getPacket();

        if($player === null)
            return;

        if($packet instanceof ContainerClosePacket) {
            if(($fakeInventory = Main::getInstance()->getFakeInventoryManager()->getInventory($player->getName())) !== null) {
                Main::getInstance()->getScheduler()->scheduleTask(new ClosureTask(function() use ($player, $fakeInventory) : void {
                    if($fakeInventory->hasChanged() && $fakeInventory->isClosed()) {
                        $fakeInventory->nextInventory->openFor([$player]);
                    }
                }));
            }
        }
    }
}
