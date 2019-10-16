<?php
declare(strict_types=1);
namespace Minion;

use ifteam\Farms\Farms;
use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Human;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\item\ItemIds;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\ActorEventPacket;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;
use pocketmine\world\sound\BlockBreakSound;
use pocketmine\world\sound\BlockPlaceSound;

class Minion extends Human{

	public const TYPE_WHEAT = 1;

	public const TYPE_MINE = 2;

	/** @var int */
	protected $type;

	/** @var string */
	protected $owner;

	protected $tickQueue = 300;

	/** @var MinionInventory */
	protected $minionInventory;

	public function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);
		if(!$nbt->hasTag("owner", StringTag::class)){
			$this->close();
		}else{
			$this->owner = $nbt->getString("owner");
		}
		if(!$nbt->hasTag("type", IntTag::class)){
			$this->close();
		}else{
			$this->type = $nbt->getInt("type");
		}

		$this->minionInventory = new MinionInventory($this);

		$inventory = $nbt->getListTag("MinionInventory");

		if($inventory !== null){

			/** @var CompoundTag $item*/
			foreach($inventory as $i => $item){
				$slot = $item->getByte("Slot");
				$item = Item::nbtDeserialize($item);
				$this->minionInventory->setItem($slot, $item);
			}
		}
	}
	public function getType() : int{
		return $this->type;
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();

		$inventoryTag = new ListTag([]);

		foreach($this->minionInventory->getContents(true) as $slot => $item){
			$itemNBT = $item->nbtSerialize($slot);
			$inventoryTag->push($itemNBT);
		}

		$nbt->setTag("MinionInventory", $inventoryTag);

		$nbt->setString("owner", $this->getOwner());
		$nbt->setInt("type", $this->getType());

		return $nbt;
	}

	public function getOwner() : string{
		return $this->owner;
	}

	public function getMinionInventory() : MinionInventory{
		return $this->minionInventory;
	}

	public function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);

		if($this->isClosed() or !$this->isAlive()){
			return false;
		}

		--$this->tickQueue;
		if($this->tickQueue === 0){
			if($this->type === self::TYPE_WHEAT){
				$this->tickQueue = 300;
				$randomVec = new Vector3($this->getPosition()->getX() + mt_rand(-5, 5), $this->getPosition()->getY(), $this->getPosition()->getZ() + mt_rand(-5, 5));

				$vec = new Vector3($randomVec->getX(), $this->getPosition()->getY() - 1, $randomVec->getZ());
				if(!$this->getWorld()->isInWorld((int)$vec->getX(), (int)$vec->getY(), (int)$vec->getZ())){
					return false;
				}
				$block = $this->getWorld()->getBlock($vec);

				if($block->getId() === BlockLegacyIds::GRASS or $block->getId() === BlockLegacyIds::DIRT){
					$block->getPos()->getWorld()->setBlock($vec, BlockFactory::get(BlockLegacyIds::FARMLAND));
					$this->lookAt($vec);

					$pk = new ActorEventPacket();
					$pk->event = ActorEventPacket::ARM_SWING;
					$pk->entityRuntimeId = $this->getId();
					foreach($this->getViewers() as $player){
						$player->getNetworkSession()->sendDataPacket($pk);
					}
					$this->setNameTag($this->getOwner() . "님의 미니언");
					return $hasUpdate;
				}

				$block = $this->getWorld()->getBlock($randomVec);

				if($block->getId() === BlockLegacyIds::AIR){
					$block->getPos()->getWorld()->setBlock($block->getPos(), BlockFactory::get(BlockLegacyIds::WHEAT_BLOCK));
					$plugin = $this->server->getPluginManager()->getPlugin("Farms");

					if($plugin instanceof Farms){//FARMS SUPPORT
						$key = $block->getPos()->getX() . "." . $block->getPos()->getY() . "." . $block->getPos()->getZ();
						$plugin->farmData[$key]['id'] = BlockLegacyIds::WHEAT_BLOCK;
						$plugin->farmData[$key]['d'] = 0;
						$plugin->farmData[$key]['l'] = $block->getPos()->getWorld()->getFolderName();
						$plugin->farmData[$key]['t'] = $plugin->makeTimestamp(date("Y-m-d H:i:s"));
						$plugin->farmData[$key]['gt'] = ($plugin->get('farm-growing-time'));
					}
					$this->lookAt($vec);
					$pk = new ActorEventPacket();
					$pk->entityRuntimeId = $this->getId();
					$pk->event = ActorEventPacket::ARM_SWING;
					foreach($this->getViewers() as $player){
						$player->getNetworkSession()->sendDataPacket($pk);
					}
					$block->getPos()->getWorld()->addSound($vec, new BlockPlaceSound(BlockFactory::get(BlockLegacyIds::WHEAT_BLOCK)));
					$this->setNameTag($this->getOwner() . "님의 미니언");
					return $hasUpdate;
				}

				if($block->getId() === BlockLegacyIds::WHEAT_BLOCK){
					$meta = $block->getMeta();

					if($meta >= 7){
						$drops = $block->getDrops(ItemFactory::get(ItemIds::AIR));

						if(!$this->getMinionInventory()->canAddItem(...$drops)){
							$this->setNameTag("인벤토리에 공간이 부족합니다!" . TextFormat::EOL . $this->getOwner() . "님의 미니언");
							return false;
						}

						$this->getMinionInventory()->addItem(...$drops);

						$block->getPos()->getWorld()->setBlock($vec->setComponents($vec->getX(), $vec->getY() + 1, $vec->getZ()), VanillaBlocks::AIR());

						$this->lookAt($vec);

						$pk = new ActorEventPacket();
						$pk->entityRuntimeId = $this->getId();
						$pk->event = ActorEventPacket::ARM_SWING;
						foreach($this->getViewers() as $player){
							$player->getNetworkSession()->sendDataPacket($pk);
						}

						$block->getPos()->getWorld()->addSound($vec, new BlockBreakSound($block));
						$this->setNameTag($this->getOwner() . "님의 미니언");
						return $hasUpdate;
					}
				}
			}else{
				$this->tickQueue = 300;
				if(!$this->getInventory()->getItemInHand()->equals(VanillaItems::DIAMOND_PICKAXE())){
					$this->getInventory()->setItemInHand(VanillaItems::DIAMOND_PICKAXE());
				}

				$vec = new Vector3($this->getPosition()->getX() + mt_rand(-3, 3), $this->getPosition()->getY() - 1, $this->getPosition()->getZ() + mt_rand(-3, 3));

				if(!$this->getWorld()->isInWorld((int)$vec->getX(), (int)$vec->getY(), (int)$vec->getZ())){
					return false;
				}

				$block = $this->getWorld()->getBlock($vec);

				if($block->getId() === BlockLegacyIds::STONE){
					/** @var Block[] $drops */
					$drops = [
						VanillaBlocks::STONE(),
						VanillaBlocks::IRON_ORE(),
						VanillaBlocks::GOLD_ORE(),
						VanillaBlocks::COAL_ORE(),
						VanillaBlocks::DIAMOND_ORE(),
						VanillaBlocks::EMERALD_ORE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE(),
						VanillaBlocks::STONE()
					];

					$drop = $drops[array_rand($drops)];

					$drop = $drop->getDrops($this->getInventory()->getItemInHand());

					if(!$this->getMinionInventory()->canAddItem(...$drop)){
						$this->setNameTag("인벤토리에 공간이 부족합니다!" . TextFormat::EOL . $this->getOwner() . "님의 미니언");
						return false;
					}

					$this->getMinionInventory()->addItem(...$drop);

					$this->lookAt($vec);

					$pk = new ActorEventPacket();
					$pk->event = ActorEventPacket::ARM_SWING;
					$pk->entityRuntimeId = $this->getId();
					foreach($this->getViewers() as $player){
						$player->getNetworkSession()->sendDataPacket($pk);
					}

					$block->getPos()->getWorld()->addSound($vec, new BlockBreakSound($block));
					$this->setNameTag($this->getOwner() . "님의 미니언");
					return $hasUpdate;
				}
			}
		}
		return $hasUpdate;
	}

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() !== EntityDamageEvent::CAUSE_VOID){
			$source->setCancelled(true);

			if($source instanceof EntityDamageByEntityEvent){
				$player = $source->getDamager();
				if($player instanceof Player){
					if($player->getName() === $this->getOwner()){
						$player->setCurrentWindow($this->getMinionInventory());
					}
				}
			}
		}
	}

	protected function destroyCycles() : void{
		parent::destroyCycles();
		$this->minionInventory = null;
	}
}