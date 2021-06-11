---
title: Keep carts that have persistent data stored on them but do not have any line items.
issue: NEXT-15702
author: Andreas Allacher
author_email: andreas.allacher@massiveart.com
author_github: @AndreasA
---
# Core
* Changed method `save` in `Shopware\Core\Checkout\Cart\CartPersister` to only delete carts, if those do not have line items or any data that has to be kept like customer comment.
