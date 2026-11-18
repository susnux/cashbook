/*!
 * SPDX-FileCopyrightText: 2025 Ferdinand Thiessen <opensource@fthiessen.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

export interface ICashbook {
	/** The Snowflake ID, a string as it exceeds the safe integer range */
	id: string
	name: string
	account: string
	currency: string
	/** The user id of the owner */
	owner: string
}

/**
 * The properties of a cashbook that can be set by the user
 */
export type ICashbookData = Pick<ICashbook, 'name' | 'account' | 'currency'>

export class Cashbook implements ICashbook {
	public id: string
	public name: string
	public account: string
	public currency: string
	public owner: string

	public constructor(data: ICashbook) {
		this.id = data.id
		this.account = data.account
		this.name = data.name
		this.currency = data.currency
		this.owner = data.owner
	}
}
