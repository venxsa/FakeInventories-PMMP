<?php

declare(strict_types=1);

namespace fakeinventories\inventories;

use fakeinventories\Main;
use fakeinventories\utils\TaskUtil;
use pocketmine\block\Block;
use pocketmine\block\inventory\BlockInventory;
use pocketmine\block\tile\Chest;
use pocketmine\block\tile\Nameable;
use pocketmine\block\tile\Tile;
use pocketmine\block\tile\TileFactory;
use pocketmine\block\utils\DyeColor;
use pocketmine\block\VanillaBlocks;
use pocketmine\event\EventPriority;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\inventory\SimpleInventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\NetworkStackLatencyPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\Server;
use pocketmine\utils\Binary;
use pocketmine\world\Position;

abstract class FakeInventory extends SimpleInventory implements BlockInventory {

    private static bool $hooksRegistered = false;
    private static array $pendingLatency = [];
    private static array $retryingOpen = [];

    protected Position $holder;

    public ?self $nextInventory = null;

    protected bool $isClosed = false;
    protected bool $hasChanged = false;

    protected array $chests = [];

    public function __construct(protected string $title = "Fake Inventory", protected int $size = FakeInventorySize::SMALL_CHEST, protected bool $inventoryBehindPlayer = true) {
        parent::__construct($size);

        $this->nextInventory = $this;
        $this->holder = new Position(0, 0, 0, null);
        $this->clearAll();
        $this->setItems();
    }

    private static function ensureNetworkHooks() : void {
        if(self::$hooksRegistered) {
            return;
        }

        self::$hooksRegistered = true;

        $plugin = Main::getInstance();
        $pm = Server::getInstance()->getPluginManager();

        $pm->registerEvent(DataPacketDecodeEvent::class, static function(DataPacketDecodeEvent $event) : void {
            $player = $event->getOrigin()->getPlayer();
            if($player === null) {
                return;
            }

            $name = $player->getName();
            $id = $event->getPacketId();

            if(($id === NetworkStackLatencyPacket::NETWORK_ID && isset(self::$pendingLatency[$name])) || ($id === ContainerClosePacket::NETWORK_ID && isset(self::$retryingOpen[$name]))) {
                $event->uncancel();
            }
        }, EventPriority::LOWEST, $plugin, true);

        $pm->registerEvent(DataPacketReceiveEvent::class, static function(DataPacketReceiveEvent $event) : void {
            $player = $event->getOrigin()->getPlayer();
            if($player === null) {
                return;
            }

            $name = $player->getName();
            $packet = $event->getPacket();

            if($packet instanceof NetworkStackLatencyPacket && isset(self::$pendingLatency[$name])) {
                $callback = null;

                if(isset(self::$pendingLatency[$name][$packet->timestamp])) {
                    $callback = self::$pendingLatency[$name][$packet->timestamp];
                    unset(self::$pendingLatency[$name][$packet->timestamp]);
                } elseif(!empty(self::$pendingLatency[$name])) {
                    $firstKey = array_key_first(self::$pendingLatency[$name]);
                    $callback = self::$pendingLatency[$name][$firstKey];
                    unset(self::$pendingLatency[$name][$firstKey]);
                }

                if(empty(self::$pendingLatency[$name])) {
                    unset(self::$pendingLatency[$name]);
                }

                if($callback !== null) {
                    $callback();
                }
                return;
            }

            if($packet instanceof ContainerClosePacket && isset(self::$retryingOpen[$name])) {
                if(Binary::signByte($packet->windowId) === ContainerIds::NONE) {
                    $entry = self::$retryingOpen[$name];
                    if($entry["attempts"] > 0 && !$entry["inventory"]->isClosed() && $entry["inventory"] === $player->getCurrentWindow()) {
                        $entry["attempts"]--;
                        $entry["retrying"] = true;
                        self::$retryingOpen[$name] = $entry;
                    } else {
                        unset(self::$retryingOpen[$name]);
                    }
                } else {
                    unset(self::$retryingOpen[$name]);
                }
            }
        }, EventPriority::LOWEST, $plugin);

        $pm->registerEvent(PlayerQuitEvent::class, static function(PlayerQuitEvent $event) : void {
            $name = $event->getPlayer()->getName();
            unset(self::$pendingLatency[$name], self::$retryingOpen[$name]);
        }, EventPriority::MONITOR, $plugin);
    }

    public static function getBlockBehindPlayer(Player $player) : Vector3 {
        $yaw = $player->getLocation()->getYaw();

        $rotation = fmod($yaw - 90, 360);
        if($rotation < 0){
            $rotation += 360.0;
        }

        if((0 <= $rotation && $rotation < 45) || (315 <= $rotation && $rotation < 360)){
            return $player->getPosition()->floor()->add(2, 0, 0);
        }elseif(45 <= $rotation && $rotation < 135){
            return $player->getPosition()->floor()->add(0, 0, 2);
        }elseif(135 <= $rotation && $rotation < 225){
            return $player->getPosition()->floor()->subtract(2, 0, 0);
        }elseif(225 <= $rotation && $rotation < 315){
            return $player->getPosition()->floor()->subtract(0, 0, 2);
        }else{
            return $player->getPosition()->floor();
        }
    }

    abstract public function setItems() : void;

    abstract public function onTransaction(Player $player, Item $sourceItem, Item $targetItem, int $slot) : bool;

    private function blockPacket(Vector3 $pos, Block $block) : UpdateBlockPacket {
        return UpdateBlockPacket::create(
            BlockPosition::fromVector3($pos),
            TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($block->getStateId()),
            UpdateBlockPacket::FLAG_NETWORK,
            UpdateBlockPacket::DATA_LAYER_NORMAL
        );
    }

    private function blockActorDataPacket(Vector3 $pos, ?Vector3 $pair) : BlockActorDataPacket {
        $blockActorDataTag = CompoundTag::create()
            ->setString(Tile::TAG_ID, TileFactory::getInstance()->getSaveId(Chest::class))
            ->setInt(Tile::TAG_X, (int) $pos->x)
            ->setInt(Tile::TAG_Y, (int) $pos->y)
            ->setInt(Tile::TAG_Z, (int) $pos->z)
            ->setString(Nameable::TAG_CUSTOM_NAME, $this->title);

        if($pair !== null) {
            $blockActorDataTag
                ->setInt(Chest::TAG_PAIRX, (int) $pair->x)
                ->setInt(Chest::TAG_PAIRZ, (int) $pair->z);
        }

        return BlockActorDataPacket::create(
            BlockPosition::fromVector3($pos),
            new CacheableNbt($blockActorDataTag)
        );
    }

    public function onClose(Player $who) : void {
        $name = $who->getName();

        if(isset(self::$retryingOpen[$name]) && self::$retryingOpen[$name]["retrying"]) {
            parent::onClose($who);
            self::$retryingOpen[$name]["retrying"] = false;

            Main::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($who) : void {
                $this->attemptOpenWindow($who);
            }), 3);
            return;
        }

        unset(self::$retryingOpen[$name]);
        $this->isClosed = true;

        parent::onClose($who);

        if(!$this->hasChanged) {
            $this->closeFor($who);
        }

        Main::getInstance()->getFakeInventoryManager()->unsetInventory($name);

        if(Server::getInstance()->isRunning()) {
            TaskUtil::sendTask(function() : void {
                $this->hasChanged = false;
            });
        }
    }

    public function closeFor(Player $player) : void {
        foreach($this->chests as $position) {
            $block = $player->getWorld()->getBlock($position);
            $player->getNetworkSession()->sendDataPacket($this->blockPacket($block->getPosition(), $block));
        }
    }

    public function openFor(array $players) : void {
        self::ensureNetworkHooks();

        $players = array_values($players);
        if(empty($players)) {
            return;
        }

        $this->isClosed = false;

        foreach($players as $player) {
            $name = $player->getName();
            unset(self::$pendingLatency[$name], self::$retryingOpen[$name]);

            if($player->getCurrentWindow() instanceof FakeInventory) {
                $player->removeCurrentWindow();
            }
        }

        $chestPosition = $this->getChestPosition($players);

        $pos = $chestPosition->add(0, 2, 0);
        $this->holder = new Position($pos->x, $pos->y, $pos->z, $players[0]->getWorld());

        $this->sendAll($players, $pos);

        $extraDelay = $this->size === FakeInventorySize::LARGE_CHEST ? 2 : 1;

        foreach($players as $player) {
            $name = $player->getName();
            $timestamp = mt_rand(1, 1000000) * 1000;
            $triggered = false;

            $triggerOpen = function() use ($player, &$triggered, $extraDelay) : void {
                if($triggered || !$player->isOnline() || $this->isClosed) {
                    return;
                }

                $triggered = true;

                Main::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void {
                    $this->startOpenAttempts($player);
                }), $extraDelay);
            };

            self::$pendingLatency[$name][$timestamp] = $triggerOpen;
            $player->getNetworkSession()->sendDataPacket(NetworkStackLatencyPacket::request($timestamp));

            Main::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($name, $timestamp, $triggerOpen, &$triggered) : void {
                if(!$triggered) {
                    unset(self::$pendingLatency[$name][$timestamp]);
                    if(empty(self::$pendingLatency[$name])) {
                        unset(self::$pendingLatency[$name]);
                    }
                    $triggerOpen();
                }
            }), 15);
        }
    }

    private function startOpenAttempts(Player $player) : void {
        if(!$player->isOnline() || $this->isClosed) {
            return;
        }

        self::$retryingOpen[$player->getName()] = [
            "inventory" => $this,
            "attempts" => 6,
            "retrying" => false
        ];

        $this->attemptOpenWindow($player);
    }

    private function attemptOpenWindow(Player $player) : void {
        if(!$player->isOnline() || $this->isClosed) {
            unset(self::$retryingOpen[$player->getName()]);
            return;
        }

        Main::getInstance()->getFakeInventoryManager()->setInventory($player->getName(), $this);
        $player->setCurrentWindow($this);
    }

    public function changeInventory(Player $player, FakeInventory $inventory) : void {
        unset(self::$retryingOpen[$player->getName()]);
        $this->isClosed = true;

        $this->nextInventory = clone $inventory;

        if($this->size !== $inventory->getSize() || !$this->holder->equals($this->getChestPosition([$player])->add(0, 2, 0))) {
            $this->hasChanged = true;

            TaskUtil::sendTask(function() use ($player) : void {
                $this->closeFor($player);
            });
        }

        TaskUtil::sendTask(function() use ($inventory, $player) : void {
            $inventory->openFor([$player]);
        });
    }

    public function sendAll(array $players, Vector3 $vector3) : void {
        $this->chests = [];

        $position = clone $vector3;
        $packets = [];

        $packets[] = $this->blockPacket($position, VanillaBlocks::CHEST());
        $this->chests[] = $position;

        if($this->size === FakeInventorySize::LARGE_CHEST) {
            $pairPos = $position->add(1, 0, 0);
            $this->chests[] = $pairPos;

            $packets[] = $this->blockPacket($pairPos, VanillaBlocks::CHEST());
            $packets[] = $this->blockActorDataPacket($position, $pairPos);
            $packets[] = $this->blockActorDataPacket($pairPos, $position);
        } else {
            $packets[] = $this->blockActorDataPacket($position, null);
        }

        foreach($players as $player) {
            foreach($packets as $packet) {
                $player->getNetworkSession()->sendDataPacket($packet);
            }
        }
    }

    public function getChestPosition(array $players) : Vector3 {
        $players = array_values($players);
        $chestPosition = new Vector3(0, 0, 0);

        if(count($players) <= 1) {
            foreach($players as $player) {
                $chestPosition = self::getBlockBehindPlayer($player);

                if(!$this->inventoryBehindPlayer) {
                    $chestPosition = $player->getPosition()->floor();
                }
            }
        } else {
            $x = round(($players[0]->getPosition()->x + $players[1]->getPosition()->x) / 2);
            $y = round(($players[0]->getPosition()->y + $players[1]->getPosition()->y) / 2);
            $z = round(($players[0]->getPosition()->z + $players[1]->getPosition()->z) / 2);

            $chestPosition = (new Vector3($x, $y, $z))->floor();
        }

        return $chestPosition;
    }

    public function unClickItem(Player $player) : void {
        $player->getNetworkSession()->getInvManager()?->syncSlot($player->getCursorInventory(), 0);
    }

    public static function getDefaultFillItem() : Item {
        return VanillaBlocks::STAINED_GLASS_PANE()->setColor(DyeColor::GRAY())->asItem()->setCustomName(" ");
    }

    public function fill(?Item $item = null) : void {
        $item ??= self::getDefaultFillItem();

        for($i = 0; $i < $this->getSize(); $i++)
            if($this->isSlotEmpty($i)) {
                $this->setItem($i, clone $item);
            }
    }

    public function fillWithPattern(array $pattern, ?Item $item = null) : void {
        $item ??= self::getDefaultFillItem();

        foreach($pattern as $slot)
            $this->setItem($slot, clone $item);
    }

    public function setItem(int $index, Item $item, bool $send = true, bool $reset = false) : void {
        if($reset && !$item->isNull() && $item->getCustomName() !== "") {
            $item->setCustomName("§r" . $item->getCustomName());
        }

        parent::setItem($index, $item);
    }

    public function setItemAt(int $x, int $y, Item $item, bool $send = true, bool $reset = true) : void {
        $this->setItem((9 * $y - (9 - $x)) - 1, $item, $send, $reset);
    }

    public function getItemAt(int $x, int $y) : Item {
        return $this->getItem((9 * $y - (9 - $x)) - 1);
    }

    public function getSlotAt(int $x, int $y) : int {
        return (9 * $y - (9 - $x)) - 1;
    }

    public function getName() : string {
        return "Fake Inventory";
    }

    public function getTitle() : string {
        return $this->title;
    }

    public function setTitle(string $title) : void {
        $this->title = $title;
    }

    public function getHolder() : Position {
        return $this->holder;
    }

    public function isClosed() : bool {
        return $this->isClosed;
    }

    public function isChanging() : bool {
        return $this->hasChanged;
    }

    public function hasChanged() : bool {
        return $this->hasChanged;
    }
}
